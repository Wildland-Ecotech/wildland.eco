# Wildland Ecotech

**501(c)(3) nonprofit developing open-source technology for ecological restoration.**

We restore landscapes damaged by wildfire and pollution, working at the intersection of ecology and technology to bridge the gap between landowners and a new generation eager to protect the planet.

**Website:** [wildland.eco](https://wildland.eco)
**Instagram:** [@wildland.ecotech](https://www.instagram.com/wildland.ecotech)
**LinkedIn:** [Wildland Ecotech](https://www.linkedin.com/company/wildland-ecotech/)
**Facebook:** [Wildland Ecotech](https://www.facebook.com/people/Wildland-Ecotech/61593137854373/)
**EIN:** 33-3738514

## About This Repository

This is the open-source website for Wildland Ecotech: the public-facing home for our nonprofit, featuring information about our restoration programs, project areas, and a donation platform.

The site is a Laravel application (`laravel/`) with server-rendered Blade templates that work without JavaScript. The platform is being extended to handle donations and donor accounts. See [Planned Features](docs/06-planned-features.md) for the development plan.

## Quick Start

```bash
cp .env.example .env
cp laravel/.env.example laravel/.env
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose run --rm node npm ci
docker compose run --rm node npm run build
```

Set a real `DB_PASSWORD` in both `.env` files first. The site will be available at `http://localhost:8080`. The root `.env` loads `docker-compose.dev.yml`, which mounts your working copy into the containers. See [Deployment](docs/07-deployment.md) for details, including hot reloading.

## Documentation

Technical documentation lives in [docs/](docs/):

- **[Database Schema](docs/01-database-schema.md)** — Tables, relationships, and cascade behavior
- **[Services & Commands](docs/02-services-and-commands.md)** — Service classes and artisan commands
- **[Routes & Controllers](docs/03-routes-and-controllers.md)** — Routes, controllers, and request flows
- **[Frontend](docs/04-frontend.md)** — Page structure and components
- **[AI Development Notes](docs/05-ai-development-notes.md)** — Conventions for AI-assisted development
- **[Planned Features](docs/06-planned-features.md)** — Development plan and design decisions
- **[Deployment](docs/07-deployment.md)** — Local development and server setup
- **[Style Guide](docs/08-style-guide.md)** — Colors, text roles, layout, and writing conventions

Organizational documentation lives in [docs/organization/](docs/organization/):

- **[About](docs/organization/about.md)** — Mission, history, board structure, legal status
- **[Restoration](docs/organization/restoration.md)** — Project areas, programs, impact metrics
- **[Vision and Growth](docs/organization/vision.md)** — Ecotech projects, grants, and partnerships
- **[Content](docs/organization/content.md)** — Photo and video index for restoration media

## Contributing

All of our projects are open source. Contributions, issues, and ideas are welcome. Please see our documentation for context on the project before contributing.

## License

See [LICENSE](LICENSE) for details.
