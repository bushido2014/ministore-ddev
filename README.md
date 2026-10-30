
# MiniStore — Drupal E-commerce

A Drupal 11 e-commerce project with a custom theme inspired by the [MiniStore template by TemplatesJungle](https://demo.templatesjungle.com/ministore/index.html).

## Features

- Custom Drupal theme inspired by the MiniStore design
- Drupal Commerce integration
- Responsive e-commerce storefront
- Flexible content management with Paragraphs
- SEO-friendly URLs with Pathauto
- Enhanced administration with Admin Toolbar
- Breadcrumb navigation
- Sliders:	SwiperJS
- Mobile nav:	mmenu-light.js
- Form building with Webform

## Tech Stack

- **CMS:** Drupal 11
- **E-commerce:** Drupal Commerce 3
- **Frontend:** HTML5, CSS3, JavaScript
- **Backend:** PHP
- **Content management:** Paragraphs, Views, Views Reference
- **SEO:** Pathauto
- **CLI:** Drush 13
- **Dependency management:** Composer
- **Development environment:** DDEV
- **Cloud development:** GitHub Codespaces

## Design Inspiration

**MiniStore — TemplatesJungle**

[View the original demo](https://demo.templatesjungle.com/ministore/index.html)

## Development

This project uses DDEV and GitHub Codespaces for development.

From the repository root, run:

```bash
cd drupal
ddev start
```

### Setup

```bash
# Clone the repository
git clone https://github.com/bushido2014/ministore-ddev.git
cd ministore-ddev

# Start DDEV
ddev start

# Import the database
ddev import-db --file=db/site.sql.gz

# Install Composer dependencies
ddev composer install

# Open the site
ddev launch
```

### Useful DDEV Commands

```bash
ddev start          # Start the environment
ddev stop           # Stop the environment
ddev ssh            # SSH into the container
ddev drush cr       # Clear Drupal cache
ddev launch         # Open site in browser
ddev export-db --file=db/site.sql.gz  # Export database
```

---

## Project Structure

ministore-ddev/
├── .ddev/ # DDEV configuration
├── db/ # Database exports
├── themes/
│ └── ministore/ # Custom Drupal theme
│ ├── css/
│ ├── js/
│ └── templates/
├── modules/ # Custom modules (if any)
└── save.sh # Git backup script

## Credits

- Design inspiration: [TemplatesJungle — MiniStore](https://demo.templatesjungle.com/ministore/index.html)
- CMS: [Drupal](https://www.drupal.org/)
- E-commerce: [Drupal Commerce](https://www.drupal.org/project/commerce)
- Development environment: [DDEV](https://ddev.com/)
- Cloud development: [GitHub Codespaces](https://github.com/features/codespaces)
