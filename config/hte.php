<?php

// HTE (2026-10-16): HTE's own values come from the environment (/opt/cortendesk/.env on the VPS),
// never from this repository.
return [
    // The e-mail domain people sign in with, shown on the sign-in page ("your @<domain> account").
    // Empty: generic words.
    'signin_domain' => trim((string) env('HTE_SIGNIN_DOMAIN', ''), " \t@"),
];
