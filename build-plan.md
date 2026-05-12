# Skillsaw — Build Plan
*Last updated: 2026-05-11*

## Overview

Skillsaw is a WordPress plugin with two surfaces:

1. **Admin Dashboard** — recruiter-facing screen inside WP Admin for configuring roles, managing chatbot settings, and reviewing completed candidate sessions (transcripts, skill ratings, file downloads).
2. **Candidate Chat** — chatbot embedded in job application pages via `[skillsaw role="..."]` shortcode. Session data is pushed to Greenhouse when the candidate submits their application.

**Deadline:** May 22, 2026
**Maintained by:** Automattic staff
**Hosted on:** WordPress.com / Automattic infrastructure
**AI provider:** Anthropic (Claude), running on Automattic's Anthropic account

---

## Application Form Integration

The Automattic job application form at `automattic.com/work-with-us/job/...` is a **custom WordPress form** (not a Greenhouse iframe), rendered directly on the WP page with `<form id="application_form" method="post">`. It has no `action` attribute — it POSTs to the current page, which WP handles server-side and pushes to Greenhouse.

This means:
- Skillsaw can inject a hidden `<input name="skillsaw_session_token">` directly into the form via JS
- Skillsaw JS can read the `#email` field from the same page (no need to ask the candidate for their email in the chat)
- The session token travels with the form POST, and Skillsaw's finalize endpoint is called at form submission time

---

## Technology Stack

### Backend (PHP)
| Component | Technology |
|---|---|
| Plugin framework | WordPress Plugin API (PHP 8.1+) |
| Data storage | 6 custom MySQL tables via `dbDelta()` |
| REST API | WordPress REST API (built-in) |
| Anthropic API client | `wp_remote_post()` (no external library needed) |
| Greenhouse API client | `wp_remote_post()` |
| File storage | WordPress Media Library (25 MB max) |
| Rate limiting | WordPress Transients API |
| Secrets storage | WordPress Options API |

### AI (Anthropic / Claude)
| Use case | Model |
|---|---|
| Candidate conversation | `claude-sonnet-4-6` |
| Post-session skill evaluation | `claude-sonnet-4-6` |
| Critique document generation | `claude-opus-4-7` |

Claude reads uploaded files (PDF, DOCX, etc.) via base64-encoded document blocks in the API request — no third-party file parsing needed.

### Admin Dashboard Frontend (React)
| Component | Technology |
|---|---|
| Build tool | `@wordpress/scripts` (webpack + Babel) |
| UI components | `@wordpress/components` |
| React layer | `@wordpress/element` |
| API calls | `@wordpress/api-fetch` |

### Candidate Chat Frontend (React)
Separate, lighter React bundle compiled by `@wordpress/scripts`. Uses custom CSS derived from the design tokens in the handoff — no `@wordpress/components` (which looks like WP Admin).

---

## Database Schema

### `wp_skillsaw_roles`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `title` | e.g., "Staff Frontend Engineer" |
| `division` | Engineering, Product, Growth, Operations |
| `team` | WooCommerce, Jetpack, etc. |
| `status` | `active`, `inactive`, `draft` |
| `instructions` | Free-text recruiter instructions for the bot |
| `created_at`, `updated_at` | Timestamps |

### `wp_skillsaw_skills`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `role_id` | FK → roles |
| `name` | e.g., "React architecture" |
| `sort_order` | Display order |

### `wp_skillsaw_documents`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `role_id` | FK → roles |
| `attachment_id` | WP Media Library ID |
| `name` | Display filename |
| `type` | `pdf`, `md`, `doc`, etc. |
| `skills` | JSON array of skill names |
| `is_critique_version` | Boolean — AI-generated imperfect revision? |
| `parent_document_id` | If critique version, points to original |
| `critique_text` | Stored text of AI-generated revision |

### `wp_skillsaw_sessions`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `role_id` | FK → roles |
| `session_token` | UUID — used by embed to identify session |
| `candidate_name` | Set at form submission |
| `candidate_email` | Set at form submission — identity key |
| `mode` | `upload` or `critique` |
| `status` | `in_progress`, `complete`, `submitted_to_greenhouse` |
| `started_at`, `completed_at` | Timestamps |
| `ip_hash` | Hashed IP (for rate limiting, never plain text) |
| `greenhouse_candidate_id` | Filled after Greenhouse push |
| `gh_pushed_at` | Timestamp of Greenhouse push |

### `wp_skillsaw_messages`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `session_id` | FK → sessions |
| `role` | `bot` or `user` |
| `content` | Message text |
| `attachment_id` | WP Media Library ID (if file upload) |
| `created_at` | Timestamp |

### `wp_skillsaw_skill_ratings`
| Column | Description |
|---|---|
| `id` | Unique identifier |
| `session_id` | FK → sessions |
| `skill_name` | e.g., "React architecture" |
| `rating` | `obvious_success`, `provided_response`, `no_response`, `obvious_failure` |

---

## REST API Endpoints

Base path: `/wp-json/skillsaw/v1/`

### Admin endpoints (require WP authentication)
| Method | Path | Description |
|---|---|---|
| `GET` | `/roles` | List all roles |
| `POST` | `/roles` | Create a role |
| `PUT` | `/roles/{id}` | Update role (title, skills, instructions, status) |
| `DELETE` | `/roles/{id}` | Delete a role |
| `POST` | `/roles/{id}/documents` | Upload a reference document |
| `DELETE` | `/roles/{id}/documents/{doc_id}` | Remove a document |
| `POST` | `/roles/{id}/documents/{doc_id}/generate-critique` | Generate AI imperfect revision |
| `GET` | `/candidates` | List sessions with filters |
| `GET` | `/candidates/{session_id}/transcript` | Full transcript |
| `GET` | `/sessions/{session_id}/files/{attachment_id}` | Download a file |
| `GET / PUT` | `/settings` | Read or save API keys + config |

### Public endpoints (rate-limited, nonce-protected)
| Method | Path | Description |
|---|---|---|
| `POST` | `/sessions/start` | Start a session; returns session token |
| `POST` | `/sessions/{token}/message` | Send a message; returns bot reply |
| `POST` | `/sessions/{token}/upload` | Upload a file during chat |
| `POST` | `/sessions/{token}/finalize` | Called at form submit; accepts name + email; pushes to Greenhouse |

---

## Key Feature Notes

### "Generate Critique" flow
1. Recruiter uploads a reference document (e.g., a communications strategy PDF)
2. Recruiter clicks "Generate critique"
3. Plugin sends document text to Claude: *"Create a revised version with several realistic but non-obvious weaknesses a strong [role] candidate should identify"*
4. Claude returns revised text, stored as `critique_text` on a new document row (`is_critique_version = true`)
5. In candidate chat, when candidate chooses Critique mode, this text is displayed in a styled document card

### Skill evaluation
After a session ends, a second Claude API call reads the full transcript and rates each role skill on:
- `obvious_success` — clearly demonstrated mastery
- `provided_response` — engaged but didn't distinguish themselves
- `no_response` — skill came up but wasn't addressed
- `obvious_failure` — response was far below threshold

### Candidate identity
- Email is the identity key across sessions (one person applying to two roles = two sessions, two rows in dashboard — intentional)
- Greenhouse deduplicates by email automatically on their side
- No separate candidates table — `candidate_email` on `wp_skillsaw_sessions` is sufficient

### Session token & form integration
- Shortcode injects hidden `<input name="skillsaw_session_token">` into `#application_form`
- Skillsaw JS keeps it updated as session progresses
- On form submit, JS intercepts, reads `#email` from form, calls `/finalize`, then releases the form
- No theme or form handler modifications needed

### Spam prevention
- Max 5 session starts per IP per role per 24 hours (WP Transients)
- Session token validation on every public API call
- 25 MB file size limit enforced before upload reaches server
- Sessions idle for 4+ hours marked expired

---

## Build Phases

| Phase | Description | Est. Days |
|---|---|---|
| 1 | Plugin foundation: file structure, DB tables, settings page, admin menu | 1 |
| 2 | Role management admin: Roles tab, skills, document upload, critique generation, embed code | 2 |
| 3 | Candidate embed: shortcode, React chat, session lifecycle, Upload + Critique modes, Claude conversation | 2.5 |
| 4 | AI evaluation: post-session skill rating via Claude | 0.5 |
| 5 | Candidates dashboard: Candidates tab, filters, transcript modal, file downloads | 2 |
| 6 | Greenhouse integration: finalize endpoint, Harvest API push, form JS intercept | 1–1.5 |
| 7 | Hardening: security, rate limiting, error states, mobile, production testing | 2 |
| **Total** | | **~11 days** |

### What ships May 22
Everything above.

### What moves post-May-22
- Email notifications to recruiters
- Streaming bot responses (typing animation)
- Critique playback modal (separate from transcript modal)
- Multi-site WordPress support

---

## Prerequisites Before Building

1. **Anthropic API key** — from Automattic's Anthropic account. Pasted into the plugin Settings page.
2. **Greenhouse Harvest API key** — from whoever manages Automattic's Greenhouse account. Needed before Phase 6.
3. **Greenhouse job IDs** — each role in Skillsaw needs to know its corresponding Greenhouse job ID so the finalize endpoint knows where to push.
4. **Shortcode placement** — the `[skillsaw role="..."]` shortcode needs to be added to each WP job posting page, above the `#application_form` section.

---

## Plugin File Structure (planned)

```
skillsaw/
├── skillsaw.php                  # Plugin header, bootstrap
├── includes/
│   ├── class-activator.php       # DB setup on activation
│   ├── class-api.php             # REST route registration
│   ├── class-claude.php          # Anthropic API client
│   ├── class-greenhouse.php      # Greenhouse Harvest API client
│   ├── class-roles.php           # Role CRUD logic
│   ├── class-sessions.php        # Session + message logic
│   ├── class-evaluator.php       # Post-session skill rating
│   └── class-settings.php        # Options API wrapper
├── admin/
│   └── class-admin.php           # Admin page registration + asset enqueue
├── src/
│   ├── admin/                    # React source for dashboard
│   │   ├── index.js
│   │   ├── App.jsx
│   │   ├── components/
│   │   │   ├── CandidatesTab.jsx
│   │   │   ├── RolesTab.jsx
│   │   │   ├── TranscriptModal.jsx
│   │   │   └── ...
│   └── embed/                    # React source for candidate chat
│       ├── index.js
│       └── ChatPanel.jsx
├── assets/
│   ├── js/                       # Compiled bundles (gitignored, built by CI)
│   └── css/
├── package.json
└── .wp-env.json                  # Local dev environment config
```

---

## Session Log

### Session 1 — 2026-05-11

**Accomplished:**
- Defined full architecture, technology stack, and database schema
- Clarified all key product decisions (Greenhouse integration, candidate identity via email, one-sitting sessions, 25MB file limit, skill rating four-point scale, critique document generation flow)
- Investigated Automattic's application form — confirmed it is a plain HTML `<form id="application_form">` on the WP page, NOT a Greenhouse iframe. Hidden input + JS intercept approach will work for Greenhouse integration.
- Set up development environment: GitHub repo, Docker Desktop, wp-env, @wordpress/scripts
- Built and verified Phase 1: plugin foundation, 6 custom DB tables, admin menu, settings page
- Built and verified Phase 2: full REST API, Anthropic Claude client, React admin dashboard with Roles tab (CRUD, skills, document upload, critique generation, status toggle, embed code) and Candidates tab (session list, skill chips, transcript modal)

**Bugs fixed during session:**
- wp-env failing due to space in folder name → renamed to RSM_Skillsaw
- `apiFetch` returning "not a valid JSON response" → root cause was ambiguous `status` column in SQL JOIN (needed `s.status`)
- `create_role` returning "Role not found" → `WP_REST_Request` constructor doesn't accept URL params; fixed with `set_url_params()`
- REST API nonce not configured → fixed with `wp_localize_script` + `apiFetch.use(createNonceMiddleware(...))`

**Decisions deferred:**
- Whether to use Automattic's internal `training-simulator` chat library for Phase 3 — Alex checking with co-workers

**Up next (Phase 3):**
Start a fresh Claude Code session. Say: "I'm building a WordPress plugin called Skillsaw. The code is at `/Users/alexkemmler/RSM_Skillsaw/`. Read `build-plan.md` and the existing code, then let's build Phase 3 — the candidate chat embed."
