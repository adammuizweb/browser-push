CREATE TABLE IF NOT EXISTS push_article_publications (
    article_id INT UNSIGNED NOT NULL,
    event_id CHAR(64) DEFAULT NULL,
    status_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(32) NOT NULL,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    fail_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_count INT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(500) DEFAULT NULL,
    claimed_at_utc DATETIME(6) NOT NULL,
    finished_at_utc DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (article_id),
    UNIQUE KEY unique_push_article_event (event_id),
    KEY push_article_publication_state (state, claimed_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, `value`)
VALUES ('push_auto_notify_articles', '0')
ON DUPLICATE KEY UPDATE `key` = VALUES(`key`);

INSERT IGNORE INTO ui_translations (scope, source, value, locale) VALUES
('browser-push', 'Article automation', 'Otomatisasi Artikel', 'id'),
('browser-push', 'Notify once per Article', 'Beri tahu satu kali per Artikel', 'id'),
('browser-push', 'Send the Article title and canonical link when an Article reaches Published. Each Article gets at most one automatic attempt.', 'Kirim judul Artikel dan tautan kanonis saat Artikel mencapai status Published. Setiap Artikel mendapat paling banyak satu percobaan otomatis.', 'id'),
('browser-push', 'Existing published Articles are marked as already seen when this is enabled. Delivery is synchronous and is not retried automatically.', 'Artikel yang sudah published ditandai sudah terlihat saat fitur ini diaktifkan. Pengiriman berlangsung sinkron dan tidak dicoba ulang secara otomatis.', 'id'),
('browser-push', 'On', 'Aktif', 'id'),
('browser-push', 'Off', 'Nonaktif', 'id'),
('browser-push', 'Article automation', 'Artikelautomatisierung', 'de'),
('browser-push', 'Notify once per Article', 'Einmal pro Artikel benachrichtigen', 'de'),
('browser-push', 'Send the Article title and canonical link when an Article reaches Published. Each Article gets at most one automatic attempt.', 'Artikeltitel und kanonischen Link senden, sobald ein Artikel veroffentlicht wird. Jeder Artikel erhalt hochstens einen automatischen Versuch.', 'de'),
('browser-push', 'Existing published Articles are marked as already seen when this is enabled. Delivery is synchronous and is not retried automatically.', 'Bereits veroffentlichte Artikel werden beim Aktivieren als gesehen markiert. Der Versand erfolgt synchron und wird nicht automatisch wiederholt.', 'de'),
('browser-push', 'On', 'Ein', 'de'),
('browser-push', 'Off', 'Aus', 'de');
