<?php

declare(strict_types=1);

// Конфигурация CMS (ТЗ блок 2 §1.6).
return [
    'base_url'           => 'http://localhost:8080',
    'data_dir'           => __DIR__ . '/../data',
    'media_dir'          => __DIR__ . '/../public/media',
    'config_dir'         => __DIR__,
    'session_timeout'    => 3600,       // сек неактивности (ТЗ блок 3 §2.3)
    'session_secure'     => false,      // true при HTTPS в production
    'debug'              => false,
    'analytics_enabled'  => true,
    'audit_play_events'  => false,
    'audit_public_events'=> false,
    'audit_rotation_keep'=> 14,
    'trusted_proxies'    => [],         // для X-Forwarded-For
];
