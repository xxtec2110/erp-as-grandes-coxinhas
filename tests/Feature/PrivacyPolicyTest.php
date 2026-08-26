<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    public function test_privacy_policy_is_publicly_accessible(): void
    {
        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('Política de Privacidade')
            ->assertSee('As Grandes Coxinhas');
    }
}
