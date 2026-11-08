<?php

return [
    // Cobrança desligada no lançamento: todo casal tem acesso completo.
    'billing_enabled' => (bool) env('BILLING_ENABLED', false),
    'min_age' => 18,
    // Match público: mínimo de itens em comum e moderação de fotos.
    'match_min_common' => (int) env('MATCH_MIN_COMMON', 1),
    // RF-89: para entrar no Match, reconfirmar a data de nascimento e ter o e-mail confirmado.
    'match_age_check' => (bool) env('MATCH_AGE_CHECK', true),
    'photo_requires_approval' => (bool) env('PHOTO_REQUIRES_APPROVAL', false),
    // Ligue quando o e-mail estiver configurado (MAIL_* no .env).
    'require_email_verification' => (bool) env('REQUIRE_EMAIL_VERIFICATION', false),
    'invite_hours' => 48,
    // Fonte única do catálogo do Sim Duplo.
    'content_dir' => env('CONTENT_DIR', base_path('../docs/conteudo')),
    'catalog_files' => [
        env('CONTENT_DIR', base_path('../docs/conteudo')).'/catalogo-sim-duplo.md',
        env('CONTENT_DIR', base_path('../docs/conteudo')).'/catalogo-fantasias-expandido.md',
    ],
    // Sugestões de fantasia: tema proibido é recusado na hora.
    'suggestions_total_limit' => 30,
    'forbidden_terms' => [
        'crian', 'menor de idade', 'menores de', 'adolescent', 'bestial', 'zoofil', 'estupr', 'forçad', 'forcad',
        'sem consentimento', 'incest', 'necrof', 'pedof', 'inconscient', 'drogad', 'embriag',
    ],
];
