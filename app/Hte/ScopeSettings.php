<?php

namespace App\Hte;

use App\Models\ConsoleAudit;
use App\Models\Setting;
use App\Services\ServerLink;

/**
 * HTE (2026-10-16): the "each person may reach only the devices they are
 * given" switch on Settings -> Server. A Livewire trait: Livewire calls
 * mountScopeSettings() by name, so SettingsPage only `use`s it and calls
 * hteSave() from save().
 */
trait ScopeSettings
{
    public bool $hteScopeEnforce = false;

    public function mountScopeSettings(): void
    {
        $this->hteScopeEnforce = Reach::enforced();
    }

    public function hteSave(): void
    {
        if ($this->hteScopeEnforce === Reach::enforced()) {
            return;
        }
        Setting::put(Reach::SETTING, $this->hteScopeEnforce ? '1' : '0');
        ConsoleAudit::record(
            'settings.hte-scope',
            'Per-person reach '.($this->hteScopeEnforce ? 'enforced' : 'no longer enforced').' by the ID server',
            'settings',
            null,
        );
    }

    /**
     * What the switch's status line says. The pull's user agent names the
     * server build, so an unpatched hbbs is called out: it would ignore the
     * scope block and enforce nothing.
     *
     * @return array{tone: string, text: string}
     */
    public static function hteScopeStatus(): array
    {
        $link = ServerLink::status();
        $agent = (string) ($link['agent'] ?? '');
        $hte = str_contains($agent, '-hte.');
        $version = trim(str_replace('cortendesk-server/', '', $agent));

        if (! Reach::enforced()) {
            return ['tone' => 'text-muted', 'text' => 'Off: scoping only decides what each person sees.'];
        }
        if (! $link['configured'] || $link['last_pull_at'] === null) {
            return ['tone' => 'text-danger', 'text' => 'On, but no ID server is fetching the policy, so nothing is enforced.'];
        }
        if (! $hte) {
            return ['tone' => 'text-danger', 'text' => 'On, but the linked ID server ('.($version ?: 'unknown build').') is not the HTE build and ignores it.'];
        }
        if (! $link['fresh']) {
            return ['tone' => 'text-warning', 'text' => 'On; the HTE ID server ('.$version.') last fetched the policy '.\Illuminate\Support\Carbon::parse($link['last_pull_at'])->diffForHumans().'.'];
        }

        return ['tone' => 'text-success', 'text' => 'On and enforced by the HTE ID server ('.$version.').'];
    }
}
