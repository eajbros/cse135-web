-- Database Schema for Reporting Application
-- Add these tables to existing collector_db database

-- Report Comments table - analyst comments and observations for each report
CREATE TABLE IF NOT EXISTS report_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    analyst_id INT NOT NULL,
    content LONGTEXT NOT NULL,
    is_markdown BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (analyst_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category),
    INDEX idx_analyst_id (analyst_id),
    INDEX idx_created_at (created_at)
);
