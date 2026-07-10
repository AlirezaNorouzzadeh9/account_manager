<?php

namespace Tests\Feature;

use App\Telegram\Support\FormatsServerSize;
use Tests\TestCase;

class FormatsServerSizeTest extends TestCase
{
    protected function formatter(): object
    {
        return new class
        {
            use FormatsServerSize;

            public function format(array $s): string
            {
                return $this->formatSizeLabel($s);
            }
        };
    }

    public function test_uses_label_suffix_when_a_client_sets_one(): void
    {
        $label = $this->formatter()->format([
            'slug' => 'g6-dedicated-2',
            'vcpus' => 2,
            'memory' => 4096,
            'price_monthly' => 36,
            'label_suffix' => ' (Dedicated)',
        ]);

        $this->assertSame('2 CPU (Dedicated) | 4GB RAM | 36$', $label);
    }

    public function test_falls_back_to_slug_based_vendor_suffix_when_unset(): void
    {
        $amd = $this->formatter()->format([
            'slug' => 's-1vcpu-2gb-amd',
            'vcpus' => 1,
            'memory' => 2048,
            'price_monthly' => 12,
        ]);
        $this->assertSame('1 CPU (AMD) | 2GB RAM | 12$', $amd);

        $plain = $this->formatter()->format([
            'slug' => 's-1vcpu-2gb',
            'vcpus' => 1,
            'memory' => 2048,
            'price_monthly' => 12,
        ]);
        $this->assertSame('1 CPU | 2GB RAM | 12$', $plain);
    }
}
