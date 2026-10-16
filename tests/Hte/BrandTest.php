<?php

// HTE (2026-10-16): HTE Remote's name, mark and blue accent in the console chrome; the sign-in page as
// it was when it was bind-mounted.

test('the sign-in page is HTE Remote', function () {
    $this->get('/login')->assertOk()
        ->assertSee('Welcome to HTE Remote')
        ->assertSee('assets/css/hte-auth.css', false)
        ->assertSee('assets/images/hte-remote.png', false)
        ->assertDontSee('cortendesk-sm.svg', false)
        ->assertSee('work Microsoft account');
    config(['hte.signin_domain' => 'example.org']);
    $this->get('/login')->assertSee('<b>@example.org</b> account', false);
});

test('console pages carry the mark, the blue accent and no CortenDesk in the chrome', function () {
    $f = hteFixture();
    $html = $this->actingAs($f['osvaldo'])->get('/devices/'.$f['front']->id)->assertOk()
        ->assertSee('assets/css/hte-brand.css', false)
        ->assertSee('<span class="hte-mark-name">HTE <span>Remote</span></span>', false)
        ->assertSee('© HTE, Inc.', false)
        ->assertSee('HTE Remote — remote support', false)
        ->getContent();
    expect(strpos($html, 'hte-brand.css'))->toBeGreaterThan(strpos($html, 'cortendesk.css'));
    foreach (['cortendesk-sm.svg', 'cortendesk-logo-light.svg', '© CortenDesk', '>CortenDesk</a>', 'Self-hosted RustDesk console'] as $gone) {
        expect($html)->not->toContain($gone);
    }
    $css = file_get_contents(public_path('assets/css/hte-brand.css'));
    expect($css)->toContain('--rd-accent: #0a66e8;')->toContain('--rd-shell-accent: #3b8dff;');
});
