<?php

// HTE (2026-10-16): Pest bootstrap for tests/Hte.
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Hte');
