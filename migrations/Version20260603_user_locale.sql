-- Persist language preference per user account
ALTER TABLE `user`
    ADD COLUMN locale VARCHAR(5) DEFAULT NULL COMMENT 'Preferred UI locale (bg|en), NULL = auto-detect';
