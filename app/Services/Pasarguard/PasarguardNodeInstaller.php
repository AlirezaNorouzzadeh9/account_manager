<?php

namespace App\Services\Pasarguard;

use App\Models\NodeCertificate;
use App\Models\WireguardLocation;
use App\Services\Dns\CloudflareDnsClient;
use Illuminate\Support\Collection;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

/**
 * Turns a freshly created server into a PasarGuard (Marzban-compatible) node:
 * installs Docker if missing, writes the docker-compose/.env files, generates
 * this node's own TLS certificate, and brings the "node-1" container up.
 *
 * The TLS cert/key normally CANNOT be the same fixed pair for every node:
 * the panel validates the node's certificate against the address it's
 * connecting to (SSL "IP address mismatch" otherwise), so by default each
 * node gets its own self-signed cert with subjectAltName = its own public
 * IP. Only API_KEY is a fixed shared secret across every node (see
 * config/pasarguard.php).
 *
 * If config/dns.php's Cloudflare credentials are set AND a WireGuard
 * profile is assigned to this server, this instead points the node at a
 * DNS subdomain named after that PROFILE (e.g. "germany.node.pcbot.top" →
 * the node's IP) and uses ONE fixed self-signed wildcard cert for every
 * node — the panel then connects by that domain name, which the wildcard
 * SAN always covers, so the same cert genuinely works everywhere. Naming
 * the subdomain after the profile rather than the server means the domain
 * follows that identity even if the underlying server is later replaced
 * (see ReplaceServerFinishJob). A server with no profile assigned has no
 * stable identity to hang a domain off of, so it always gets the classic
 * per-IP cert instead.
 */
class PasarguardNodeInstaller
{
    protected const REMOTE_DIR = '/root/pg-node-1';
    protected const DATA_DIR = '/var/lib/pg-node-1';

    // Shared by every WireGuard location's [Interface]/[Peer] — only
    // ip/server_public_key (per WireguardLocation) and PrivateKey (per
    // WireguardProfile, chosen per server) actually vary.
    protected const WG_ADDRESS = '10.14.0.2/16';
    protected const WG_DNS = '162.252.172.57, 149.154.159.92';
    protected const WG_ALLOWED_IPS = '0.0.0.0/0';
    protected const WG_PORT = 51820;
    protected const WG_TABLE = 'off';

    protected const COMPOSE_YAML = <<<'YAML'
services:
  node-1:
    container_name: node-1
    image: pasarguard/node:latest
    restart: always
    network_mode: host
    privileged: true
    cap_add:
      - NET_ADMIN
    env_file: node-1/.env
    volumes:
      - /var/lib/pg-node-1:/var/lib/pg-node-1
YAML;

    /**
     * @return array{success: bool, message: string, log: string, cert: string, domain: ?string, dns_warning: ?string}
     */
    public function install(
        string $host,
        string $username,
        string $password,
        ?string $wireguardPrivateKey = null,
        ?string $wireguardProfileName = null,
    ): array {
        $ssh = $this->connectSsh($host, $username, $password, 'اتصال SSH ناموفق بود (احتمالاً سرور هنوز کاملاً آماده نشده).');

        $log = '';
        // No profile PrivateKey chosen for this server => no WireGuard,
        // regardless of how many locations are saved (a [Interface] can't be
        // built without a PrivateKey).
        $wireguardConfigs = $wireguardPrivateKey !== null ? WireguardLocation::all() : collect();

        // A just-booted droplet may still be running its first-boot apt
        // operations (cloud-init), holding the dpkg lock — wait it out first,
        // otherwise our own apt/docker install below can fail or hang.
        $ssh->setTimeout(120);
        $log .= "\$ cloud-init status --wait\n".$this->run($ssh, 'cloud-init status --wait 2>&1');
        $ssh->setTimeout(20);

        [$dockerOk, $dockerLog] = $this->ensureDocker($ssh);
        $log .= $dockerLog;

        if (! $dockerOk) {
            return [
                'success' => false,
                'message' => 'نصب Docker روی سرور ناموفق بود.',
                'log' => $log,
                'cert' => '',
                'domain' => null,
                'dns_warning' => null,
            ];
        }

        if ($wireguardConfigs->isNotEmpty()) {
            $log .= $this->ensureWireguardTools($ssh);
        }

        $this->run($ssh, 'mkdir -p '.escapeshellarg(self::REMOTE_DIR.'/node-1').' '.escapeshellarg(self::DATA_DIR.'/certs'));

        $this->writeRemoteFile($ssh, self::REMOTE_DIR.'/docker-compose.yml', self::COMPOSE_YAML);
        $this->writeRemoteFile($ssh, self::REMOTE_DIR.'/node-1/.env', $this->buildEnvFile($wireguardConfigs->isNotEmpty()));
        [$certLog, $certContent, $domain, $dnsWarning] = $this->setUpCertificate($ssh, $host, $wireguardProfileName);
        $log .= $certLog;

        // A fresh droplet's network/DNS can still be settling right after boot
        // (e.g. transient failures resolving registry-1.docker.io), so retry
        // the image pull/up a few times before giving up.
        $ssh->setTimeout(60);
        $nodeUp = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $log .= "\$ docker compose up -d (attempt {$attempt}/3)\n".
                $this->run($ssh, 'cd '.escapeshellarg(self::REMOTE_DIR).' && docker compose up -d 2>&1');

            $running = trim($this->run($ssh, "docker inspect -f '{{.State.Running}}' node-1 2>&1"));
            $nodeUp = $running === 'true';

            if ($nodeUp || $attempt === 3) {
                break;
            }

            sleep(10);
        }

        if (! $nodeUp) {
            $log .= "\n\$ docker compose logs --tail 30 node-1\n".$this->run($ssh, 'cd '.escapeshellarg(self::REMOTE_DIR).' && docker compose logs --tail 30 node-1');
        }

        $this->resetExistingWireguards($ssh);
        [$wireguardsUp, $wireguardLog] = $this->installWireguards($ssh, $wireguardConfigs, $wireguardPrivateKey);
        $log .= $wireguardLog;

        if (! $nodeUp || ! $wireguardsUp) {
            return [
                'success' => false,
                'message' => $nodeUp
                    ? 'نود بالا آمد ولی حداقل یکی از کانفیگ‌های وایرگارد فعال نشد.'
                    : 'کانتینر نود بالا نیامد. لاگ کانتینر بررسی شود.',
                'log' => $log,
                'cert' => $certContent,
                'domain' => $domain,
                'dns_warning' => $dnsWarning,
            ];
        }

        $message = 'نود پاسارگارد با موفقیت نصب و اجرا شد.';

        if ($wireguardConfigs->isNotEmpty()) {
            $message .= " ({$wireguardConfigs->count()} کانفیگ وایرگارد هم فعال شد.)";
        }

        return [
            'success' => true,
            'message' => $message,
            'log' => $log,
            'cert' => $certContent,
            'domain' => $domain,
            'dns_warning' => $dnsWarning,
        ];
    }

    /**
     * A droplet that just answered an ICMP ping (which is all the "replace
     * server" flow waits for before handing off here) doesn't necessarily
     * have sshd up yet — cloud-init/systemd can still be a few seconds
     * behind the network coming up, and DigitalOcean marking the create
     * action "completed" isn't a guarantee either. Retries connection
     * refused/timeout instead of failing the whole install over a race.
     */
    protected function connectSsh(string $host, string $username, string $password, string $failureMessage): SSH2
    {
        $maxAttempts = 6;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ssh = new SSH2($host, 22);
            $ssh->setTimeout(20);

            try {
                if ($ssh->login($username, $password)) {
                    return $ssh;
                }
            } catch (Throwable) {
                // connection refused/timed out/etc. — treated the same as a
                // failed login below: wait and retry.
            }

            if ($attempt < $maxAttempts) {
                sleep(10);
            }
        }

        throw new RuntimeException($failureMessage);
    }

    /**
     * (Re)applies every saved WireGuard config to an already-set-up node,
     * without touching Docker/the node container. Used both by the initial
     * install and by the standalone "update WireGuards" action.
     *
     * @return array{success: bool, message: string, log: string}
     */
    public function updateWireguards(string $host, string $username, string $password, ?string $wireguardPrivateKey = null): array
    {
        $ssh = $this->connectSsh($host, $username, $password, 'اتصال SSH ناموفق بود (احتمالاً پسورد اشتباه است یا سرور در دسترس نیست).');

        $configs = $wireguardPrivateKey !== null ? WireguardLocation::all() : collect();

        if ($configs->isNotEmpty()) {
            $this->ensureWireguardTools($ssh);
        }

        $this->resetExistingWireguards($ssh);
        [$allUp, $log] = $this->installWireguards($ssh, $configs, $wireguardPrivateKey);

        return [
            'success' => $allUp,
            'message' => $configs->isEmpty()
                ? 'هیچ کانفیگ وایرگاردی ذخیره نشده؛ اینترفیس‌های قبلی (در صورت وجود) حذف شدند.'
                : ($allUp
                    ? "همه‌ی {$configs->count()} کانفیگ وایرگارد با موفقیت بروزرسانی شدند."
                    : 'حداقل یکی از کانفیگ‌های وایرگارد فعال نشد.'),
            'log' => $log,
        ];
    }

    /**
     * Builds the full wg-quick text for one location: the location's own
     * ip/server_public_key combined with the fixed shared fields and the
     * PrivateKey of the profile chosen for this particular server.
     */
    protected function buildLocationConfig(WireguardLocation $location, string $privateKey): string
    {
        return implode("\n", [
            '[Interface]',
            'Address = '.self::WG_ADDRESS,
            "PrivateKey = {$privateKey}",
            'DNS = '.self::WG_DNS,
            'Table = '.self::WG_TABLE,
            '',
            '[Peer]',
            "PublicKey = {$location->server_public_key}",
            'AllowedIPs = '.self::WG_ALLOWED_IPS,
            "Endpoint = {$location->ip}:".self::WG_PORT,
        ]);
    }

    /**
     * Tears down every wg-quick interface currently on the box so re-applying
     * the saved configs always reflects an exact, clean sync (handles
     * renamed/removed configs correctly instead of only adding new ones).
     */
    protected function resetExistingWireguards(SSH2 $ssh): void
    {
        $files = trim($this->run($ssh, 'ls /etc/wireguard/*.conf 2>/dev/null'));

        if ($files === '') {
            return;
        }

        foreach (preg_split('/\s+/', $files) as $path) {
            $iface = basename($path, '.conf');
            $this->run($ssh, "systemctl disable --now wg-quick@{$iface} 2>&1");
            $this->run($ssh, 'rm -f '.escapeshellarg($path));
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function installWireguards(SSH2 $ssh, Collection $configs, ?string $privateKey): array
    {
        if ($configs->isEmpty() || $privateKey === null) {
            return [true, ''];
        }

        $log = '';
        $allUp = true;
        $usedNames = [];

        // Units for wg-quick@ may have just been installed by ensureWireguardTools()
        // above; reload so systemd actually sees them before we enable anything.
        $this->run($ssh, 'systemctl daemon-reload 2>&1');

        foreach ($configs->values() as $config) {
            $iface = $this->sanitizeInterfaceName($config->name, $usedNames);

            $this->writeRemoteFile($ssh, "/etc/wireguard/{$iface}.conf", $this->buildLocationConfig($config, $privateKey));
            $this->run($ssh, 'chmod 600 '.escapeshellarg("/etc/wireguard/{$iface}.conf"));
            $enableOutput = $this->run($ssh, "systemctl enable --now wg-quick@{$iface} 2>&1");

            // Check BOTH that it's running now AND that it's enabled to come
            // back up after a reboot — "wg show"/"ip link" alone only prove
            // the former, not that a reboot won't lose the tunnel.
            $enabled = trim($this->run($ssh, "systemctl is-enabled wg-quick@{$iface} 2>&1")) === 'enabled';
            $isUp = trim($this->run($ssh, "wg show {$iface} 2>&1")) !== '' || str_contains(
                $this->run($ssh, "ip link show {$iface} 2>&1"),
                'state UP'
            );
            $ok = $enabled && $isUp;

            $log .= "\$ wg-quick@{$iface} ({$config->name}): up=".($isUp ? 'yes' : 'no').', enabled='.($enabled ? 'yes' : 'no')."\n";

            if (! $ok) {
                $allUp = false;
                $log .= $enableOutput."\n";
                $log .= $this->run($ssh, "journalctl -u wg-quick@{$iface} --no-pager -n 15 2>&1")."\n";
            }
        }

        return [$allUp, $log];
    }

    /**
     * Builds a valid, unique Linux interface name (max 15 chars) from the
     * user-given config name, e.g. "it" -> "it", so /etc/wireguard/it.conf
     * matches what the user actually typed instead of an opaque wg0/wg1.
     */
    protected function sanitizeInterfaceName(string $name, array &$usedNames): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        $base = substr($base, 0, 12);

        if ($base === '') {
            $base = 'wg';
        }

        $candidate = $base;
        $suffix = 1;

        while (in_array($candidate, $usedNames, true)) {
            $candidate = substr($base, 0, 15 - strlen((string) $suffix)).$suffix;
            $suffix++;
        }

        $usedNames[] = $candidate;

        return $candidate;
    }

    /**
     * Decides which certificate strategy to use for this node: a DNS-backed
     * fixed wildcard cert (if Cloudflare credentials + a WireGuard profile
     * are available) or the classic per-node, IP-bound one otherwise.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: ?string} [log excerpt, cert PEM content, domain used (if any), DNS failure warning (if any)]
     */
    protected function setUpCertificate(SSH2 $ssh, string $ip, ?string $wireguardProfileName): array
    {
        if ($wireguardProfileName === null || ! $this->dnsConfigured()) {
            [$log, $cert] = $this->generateNodeCertificate($ssh, $ip);

            return [$log, $cert, null, null];
        }

        $domain = "{$wireguardProfileName}.".config('dns.cloudflare.node_domain');

        try {
            $this->dnsClient()->upsertARecord($domain, $ip);
        } catch (Throwable $e) {
            // DNS is best-effort here — fall back to the classic per-IP cert
            // rather than failing the whole install over it. The failure
            // reason is still returned (not just logged) so it isn't
            // silently swallowed when the rest of the install succeeds.
            [$log, $cert] = $this->generateNodeCertificate($ssh, $ip);
            $warning = "ثبت رکورد DNS برای {$domain} ناموفق بود: {$e->getMessage()}";

            return ["\$ Cloudflare DNS record for {$domain} failed: {$e->getMessage()}\n".$log, $cert, null, $warning];
        }

        [$certificate, $privateKey] = $this->ensureFixedCertificate($ssh);
        $this->installCertificate($ssh, $certificate, $privateKey);

        return ["\$ using fixed wildcard certificate (domain: {$domain})\n", $certificate, $domain, null];
    }

    protected function dnsConfigured(): bool
    {
        return filled(config('dns.cloudflare.zone_id')) && filled(config('dns.cloudflare.api_token'));
    }

    protected function dnsClient(): CloudflareDnsClient
    {
        return new CloudflareDnsClient(config('dns.cloudflare.api_token'), config('dns.cloudflare.zone_id'));
    }

    /**
     * The ONE fixed self-signed wildcard cert (SAN = *.<node_domain>) shared
     * by every node once DNS-based addressing is on — generated once (via
     * openssl over this SSH connection, same mechanism as the per-node
     * path) and cached, so later installs just reuse it.
     *
     * @return array{0: string, 1: string} [certificate PEM, private key PEM]
     */
    protected function ensureFixedCertificate(SSH2 $ssh): array
    {
        $stored = NodeCertificate::first();

        if ($stored) {
            return [$stored->certificate, $stored->private_key];
        }

        $domain = config('dns.cloudflare.node_domain');
        $certPath = self::DATA_DIR.'/certs/ssl_cert.pem';
        $keyPath = self::DATA_DIR.'/certs/ssl_key.pem';

        $cmd = 'openssl req -x509 -nodes -newkey rsa:2048 '.
            '-keyout '.escapeshellarg($keyPath).' '.
            '-out '.escapeshellarg($certPath).' '.
            '-days 3650 '.
            '-subj '.escapeshellarg("/CN=*.{$domain}").' '.
            '-addext '.escapeshellarg("subjectAltName=DNS:*.{$domain},DNS:{$domain}").' 2>&1';

        $this->run($ssh, $cmd);
        $certificate = trim($this->run($ssh, 'cat '.escapeshellarg($certPath)));
        $privateKey = trim($this->run($ssh, 'cat '.escapeshellarg($keyPath)));

        NodeCertificate::create(['certificate' => $certificate, 'private_key' => $privateKey]);

        return [$certificate, $privateKey];
    }

    protected function installCertificate(SSH2 $ssh, string $certificate, string $privateKey): void
    {
        $this->writeRemoteFile($ssh, self::DATA_DIR.'/certs/ssl_cert.pem', $certificate);
        $this->writeRemoteFile($ssh, self::DATA_DIR.'/certs/ssl_key.pem', $privateKey);
        $this->run($ssh, 'chmod 600 '.escapeshellarg(self::DATA_DIR.'/certs/ssl_key.pem'));
    }

    /**
     * @return array{0: string, 1: string} [log excerpt, generated cert PEM content]
     */
    protected function generateNodeCertificate(SSH2 $ssh, string $ip): array
    {
        $certPath = self::DATA_DIR.'/certs/ssl_cert.pem';
        $keyPath = self::DATA_DIR.'/certs/ssl_key.pem';

        $cmd = 'openssl req -x509 -nodes -newkey rsa:2048 '.
            '-keyout '.escapeshellarg($keyPath).' '.
            '-out '.escapeshellarg($certPath).' '.
            '-days 3650 '.
            '-subj '.escapeshellarg("/CN={$ip}").' '.
            '-addext '.escapeshellarg("subjectAltName=IP:{$ip}").' 2>&1';

        $output = "\$ generate node TLS cert for {$ip}\n".$this->run($ssh, $cmd);
        $this->run($ssh, 'chmod 600 '.escapeshellarg($keyPath));

        $cert = trim($this->run($ssh, 'cat '.escapeshellarg($certPath)));

        return [$output, $cert];
    }

    /**
     * @return array{0: bool, 1: string} [docker is now available, log excerpt]
     */
    protected function ensureDocker(SSH2 $ssh): array
    {
        $hasDocker = trim($this->run($ssh, 'command -v docker'));

        if ($hasDocker !== '') {
            return [true, ''];
        }

        $ssh->setTimeout(240);
        $output = "\$ install docker\n".$this->run($ssh, 'curl -fsSL https://get.docker.com | sh 2>&1');
        $ssh->setTimeout(20);

        $hasDockerNow = trim($this->run($ssh, 'command -v docker'));

        return [$hasDockerNow !== '', $output];
    }

    protected function ensureWireguardTools(SSH2 $ssh): string
    {
        $log = '';
        $hasWg = trim($this->run($ssh, 'command -v wg-quick'));

        if ($hasWg === '') {
            $log .= "\$ install wireguard-tools\n".$this->run(
                $ssh,
                'apt-get update -y >/dev/null 2>&1; apt-get install -y wireguard-tools 2>&1'
            );
        }

        // wg-quick needs resolvconf to apply a config's DNS= line; without it,
        // DNS just silently doesn't get set. Checked separately from wg-quick
        // itself since a server provisioned before this existed may already
        // have wireguard-tools but still be missing it.
        $hasResolvconf = trim($this->run($ssh, 'command -v resolvconf'));

        if ($hasResolvconf === '') {
            $log .= "\$ install openresolv\n".$this->run(
                $ssh,
                'apt-get update -y >/dev/null 2>&1; apt-get install -y openresolv 2>&1'
            );
        }

        return $log;
    }

    protected function buildEnvFile(bool $hasWireguard): string
    {
        $lines = [
            'SERVICE_PORT = 62050',
            'SSL_CERT_FILE = '.self::DATA_DIR.'/certs/ssl_cert.pem',
            'SSL_KEY_FILE = '.self::DATA_DIR.'/certs/ssl_key.pem',
            'API_KEY = '.config('pasarguard.api_key'),
        ];

        if ($hasWireguard) {
            $lines[] = 'PG_NODE_WG_HOST_ROUTING = 1';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    protected function writeRemoteFile(SSH2 $ssh, string $path, string $content): void
    {
        $encoded = base64_encode($content);
        $this->run($ssh, "echo '{$encoded}' | base64 -d > ".escapeshellarg($path));
    }

    protected function run(SSH2 $ssh, string $command): string
    {
        return (string) $ssh->exec($command);
    }
}
