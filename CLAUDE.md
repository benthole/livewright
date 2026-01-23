# LiveWright

Combined repository for LiveWright web applications.

## Project Structure

```
livewright/
├── roster/           # Roster management system (Keap integration)
├── pdp/              # Personal Development Plan pricing/contracts
└── webinar-redirect/ # Webinar redirect functionality
```

## Subdirectories

### roster/
PHP application for managing coaching program participants. Integrates with Keap/Infusionsoft for CRM functionality.
- Roster viewing and management
- Attendance tracking
- Bulk email via Keap
- Admin panel with user management

### pdp/
PHP application for managing pricing contracts and support packages.
- Contract management with pricing options
- Support package configuration
- Coaching rates management
- Admin dashboard

### webinar-redirect/
Placeholder for webinar redirect functionality.

## Shared Resources

This project uses shared skills and agents from the main Development directory:
- **Skills**: `.claude/skills/` (symlinked)
- **Agents**: `.claude/agents/` (symlinked)
- **Hooks**: `.claude/hooks/` (symlinked)

## Tech Stack
- **Backend**: PHP
- **Database**: MySQL
- **CRM**: Keap/Infusionsoft integration (roster)
- **Frontend**: Bootstrap 5
