# Xboard

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 12, focusing on providing a clean and efficient user experience.

## ✨ Features

- 🚀 Built with Laravel 12 + Octane for significant performance gains
- 🎨 Redesigned admin interface (React + Shadcn UI)
- 📱 Modern user frontend (React + TypeScript + TailwindCSS)
- 🐳 Ready-to-use Docker deployment solution
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

```bash
git clone -b compose --depth 1 https://github.com/cedar2025/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    web php artisan xboard:install && \
docker compose up -d
```

> After installation, visit: http://SERVER_IP:7001  
> ⚠️ Make sure to save the admin credentials shown during installation

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [Deploy with aaPanel + Docker](./docs/en/installation/aapanel-docker.md) (Recommended)
- [宝塔 + Docker 部署（中文）](./docs/zh/installation/aapanel-docker.md)
- [宝塔 + Docker 快速部署（中文）](./docs/zh/installation/aapanel-docker-quickstart.md)
- [宝塔 + Docker 运维手册（中文）](./docs/zh/operations/runbook.md)
- [Keli 发布流程（中文）](./docs/zh/operations/release.md)
- [备份恢复手册（中文）](./docs/zh/operations/backup-restore.md)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)
- [Migrate from wyx2685/v2board](./docs/en/migration/v2board-wyx2685.md)

## 🛠️ Tech Stack

- Backend: Laravel 12 + Octane
- Admin Panel: React + Shadcn UI + TailwindCSS
- User Frontend: React + TypeScript + TailwindCSS
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 📷 Preview
![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## 🌟 Maintenance Notice

This project is currently under light maintenance. We will:
- Fix critical bugs and security issues
- Review and merge important pull requests
- Provide necessary updates for compatibility

However, new feature development may be limited.

## 🔔 Important Notes

1. Restart required after modifying admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process

## 🤝 Contributing

Issues and Pull Requests are welcome to help improve the project.

### Composer Lock Workflow

- Lock file updates must be generated on `PHP 8.2.x`.
- Do not use `--ignore-platform-reqs` for lock updates.
- Use the helper script:

```bash
./scripts/update-composer-lock.sh
```

- To update specific packages:

```bash
./scripts/update-composer-lock.sh google/recaptcha symfony/string --with-all-dependencies
```

### Local Test Workflow

- Use PHP 8.2.x; this repository pins `8.2.30` in `.php-version`.
- Run the backend unit suite through Composer so the PHP version guard is checked first:

```bash
composer test
```

- If the host PHP version is not 8.2, run the same suite in Docker:

```bash
./scripts/test-php82-docker.sh
```

- To run a focused PHPUnit command in the PHP 8.2 container:

```bash
./scripts/test-php82-docker.sh php vendor/bin/phpunit --filter PaymentOrderRegressionTest
```

## 📈 Star History

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
