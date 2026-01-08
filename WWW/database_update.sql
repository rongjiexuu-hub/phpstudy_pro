-- =====================================================
-- 数据库更新脚本 - 添加nickname字段
-- 如果您的数据库已经存在，运行此脚本添加新字段
-- =====================================================

-- 为simple_users表添加nickname字段（如果不存在）
ALTER TABLE simple_users ADD COLUMN IF NOT EXISTS nickname VARCHAR(50) DEFAULT NULL COMMENT '昵称' AFTER username;

-- 如果上面的语句不支持（MySQL版本较低），可以使用以下方式：
-- 先检查字段是否存在，不存在则添加
-- SET @dbname = DATABASE();
-- SET @tablename = "simple_users";
-- SET @columnname = "nickname";
-- SET @preparedStatement = (SELECT IF(
--   (
--     SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
--     WHERE (TABLE_SCHEMA = @dbname) AND (TABLE_NAME = @tablename) AND (COLUMN_NAME = @columnname)
--   ) > 0,
--   "SELECT 'Column already exists'",
--   CONCAT("ALTER TABLE ", @tablename, " ADD ", @columnname, " VARCHAR(50) DEFAULT NULL COMMENT '昵称' AFTER username;")
-- ));
-- PREPARE alterIfNotExists FROM @preparedStatement;
-- EXECUTE alterIfNotExists;
-- DEALLOCATE PREPARE alterIfNotExists;

