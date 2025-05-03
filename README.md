# Bubblepop Monorepo

A WordPress project with a custom `woo-shops-library` plugin and a `price-scraper` Node.js service.

## Setup

1. **Prerequisites**:
   - Docker Desktop
   - PHP 8.1
   - Node.js 20
   - Composer

2. **Installation**:
   - Clone the repository: `git clone https://github.com/burdatomas/bubblepop.git`
   - Navigate to the project: `cd bubblepop`
   - Install PHP dependencies: `composer install`
   - Navigate to price-scraper: `cd server/price-scraper`
   - Install Node.js dependencies: `npm install`
   - Return to root: `cd ../..`
   - Start Docker services: `docker-compose up -d`

3. **Access**:
   - WordPress: `http://localhost:8000`
   - Price Scraper: `http://localhost:3000/health` (use `Authorization: Bearer 14bfb4910dc3f34aa9fa478cf4d8d550`)

4. **Plugins**:
   - Activate `WooCommerce` and `Shops Library for WooCommerce` in WordPress admin.

## Development

- **VS Code Extensions**: PHP Intelephense, ESLint, Prettier, Docker
- **Formatting**: Configured in `.vscode/settings.json`

## Future Plugins
- Planned: Advanced analytics, product comparison tools