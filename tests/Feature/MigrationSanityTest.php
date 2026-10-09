<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationSanityTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_builds_on_the_test_driver(): void
    {
        $this->assertTrue(Schema::hasTable('contracts'));
        $this->assertTrue(Schema::hasColumn('contracts', 'signer_consent_text'));
        $this->assertTrue(Schema::hasTable('notifications'));
    }
}
