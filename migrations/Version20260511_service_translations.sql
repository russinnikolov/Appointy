-- Auto-translated name/description columns for the service table

ALTER TABLE service
    ADD COLUMN source_locale   VARCHAR(5)    NOT NULL DEFAULT 'bg'  AFTER is_active,
    ADD COLUMN name_en         VARCHAR(150)  DEFAULT NULL           AFTER source_locale,
    ADD COLUMN name_bg         VARCHAR(150)  DEFAULT NULL           AFTER name_en,
    ADD COLUMN description_en  LONGTEXT      DEFAULT NULL           AFTER name_bg,
    ADD COLUMN description_bg  LONGTEXT      DEFAULT NULL           AFTER description_en;
