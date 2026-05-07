# Grafira

A web application for booking appointments between clients and local businesses, built with Symfony 7, MySQL, and Bootstrap 5.

---

## Features

- **Dual account types** — register as a Client or a Business (single login page, auto-routed by type)
- **Client** — browse organizations, book appointments, cancel pending bookings
- **Business** — view incoming appointments, confirm or cancel them, live status counters
- **Slick UI** — Bootstrap 5 + Bootstrap Icons, fully responsive

---

## Requirements

- PHP 8.2+
- Composer
- MySQL 8.0+
- Symfony CLI (optional, for local dev server)

---

## Installation

```bash
git clone <repo-url> appointy
cd appointy
composer install
```

---

## Database Setup

1. Copy and configure your environment file:

```bash
cp .env .env.local
```

2. Edit `DATABASE_URL` in `.env.local`:

```
DATABASE_URL="mysql://root:yourpassword@127.0.0.1:3306/appointy?serverVersion=8.0&charset=utf8mb4"
```

3. Create the database and schema:

```bash
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
```

---

## Running the App

**With Symfony CLI:**
```bash
symfony serve
```
Then open [http://localhost:8000](http://localhost:8000)

**With PHP built-in server:**
```bash
php -S localhost:8000 -t public/
```

---

## Database Schema

### `user`
| Column | Type | Notes |
|---|---|---|
| id | INT | Primary key |
| name | VARCHAR(150) | Full name |
| email | VARCHAR(180) | Unique |
| password | VARCHAR(255) | Bcrypt hashed |
| phone | VARCHAR(20) | Nullable |
| type | VARCHAR(10) | `client` or `business` |
| organization_id | INT | FK → organization (nullable) |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-updated |

### `organization`
| Column | Type | Notes |
|---|---|---|
| id | INT | Primary key |
| name | VARCHAR(150) | |
| email | VARCHAR(180) | Nullable |
| phone | VARCHAR(20) | Nullable |
| address | VARCHAR(255) | Nullable |
| description | TEXT | Nullable |
| category | VARCHAR(100) | e.g. Barbershop, Dental… |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-updated |

### `appointment`
| Column | Type | Notes |
|---|---|---|
| id | INT | Primary key |
| client_id | INT | FK → user |
| organization_id | INT | FK → organization |
| appointment_date | DATE | |
| appointment_time | TIME | |
| status | VARCHAR(20) | `pending`, `confirmed`, `cancelled` |
| notes | TEXT | Nullable |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-updated |

---

## Project Structure

```
src/
├── Controller/
│   ├── AuthController.php           # Login, logout, register (client + business)
│   ├── HomeController.php           # Landing page + post-login redirect
│   ├── ClientDashboardController.php  # Browse orgs, book, cancel
│   └── BusinessDashboardController.php # View, confirm, cancel appointments
├── Entity/
│   ├── User.php                     # Single user table (type: client/business)
│   ├── Organization.php
│   └── Appointment.php
└── Repository/
    ├── UserRepository.php
    ├── OrganizationRepository.php
    └── AppointmentRepository.php

templates/
├── base.html.twig                   # Bootstrap 5 layout + navbar
├── home/index.html.twig             # Public landing page
├── auth/
│   ├── login.html.twig
│   └── register.html.twig           # Tabbed: Client / Business
├── client/
│   ├── dashboard.html.twig          # My appointments
│   ├── organizations.html.twig      # Browse + search
│   └── book.html.twig               # Booking form
└── business/
    └── dashboard.html.twig          # Manage appointments + status filter
```

---

## Routes

| Route | Path | Access |
|---|---|---|
| `home` | `/` | Public |
| `login` | `/login` | Public |
| `logout` | `/logout` | Authenticated |
| `register` | `/register?type=client\|business` | Public |
| `post_login` | `/post-login` | Authenticated — redirects by type |
| `client_dashboard` | `/client` | `ROLE_CLIENT` |
| `client_organizations` | `/client/organizations` | `ROLE_CLIENT` |
| `client_book` | `/client/book/{id}` | `ROLE_CLIENT` |
| `client_cancel` | `/client/cancel/{id}` | `ROLE_CLIENT` |
| `business_dashboard` | `/business` | `ROLE_BUSINESS` |
| `business_confirm` | `/business/confirm/{id}` | `ROLE_BUSINESS` |
| `business_cancel` | `/business/cancel/{id}` | `ROLE_BUSINESS` |

---

## Tech Stack

- **[Symfony 7](https://symfony.com/)** — PHP framework
- **[Doctrine ORM](https://www.doctrine-project.org/)** — database abstraction
- **[Bootstrap 5.3](https://getbootstrap.com/)** — UI framework (CDN)
- **[Bootstrap Icons](https://icons.getbootstrap.com/)** — icon set (CDN)
- **MySQL 8.0** — database
