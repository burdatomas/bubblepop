require('dotenv').config();
const winston = require('winston');
const path = require('path');

// Configuration constants
const CONFIG = {
  PORT: process.env.PORT || 3000,
  AUTH_TOKEN: process.env.AUTH_TOKEN || 'default-key',
  SUPPORTED_CURRENCIES: ['CZK', 'EUR', 'PLN'],
  LOG_DIR: path.join(__dirname, process.env.LOG_DIR || 'logs'),
  MAX_RETRIES: 3,
  TIMEOUT: 60000
};

// Initialize logger
const jobLogger = winston.createLogger({
  level: 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.json()
  ),
  transports: [
    new winston.transports.File({
      filename: path.join(CONFIG.LOG_DIR, 'bot_challenges.log'),
      level: 'info'
    }),
    new winston.transports.Console()
  ]
});

// Ensure log directory exists
const fs = require('fs').promises;
fs.mkdir(CONFIG.LOG_DIR, { recursive: true }).catch(err => {
  console.error(`Failed to create log directory: ${err.message}`);
});

module.exports = { CONFIG, jobLogger };