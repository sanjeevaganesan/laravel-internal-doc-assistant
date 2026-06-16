# New Employee Onboarding Guide

Welcome to the team! This guide walks you through everything you need to do in
your first 30 days to get set up, productive, and connected with your colleagues.
If you have questions at any point, your onboarding buddy or HR can help.

Last updated: 2026-02-01

---

## Before Your First Day

HR will send you a welcome email 3 business days before your start date with:
- Instructions for completing I-9 verification (bring valid ID documents on day 1)
- A link to set up your company email account
- The office address (if relevant) and badge pickup instructions
- Information about your assigned onboarding buddy

Your IT equipment will be shipped to your home address for remote employees, or
available for pickup at the office on day 1 for in-person employees. If your
laptop hasn't arrived by your start date, contact it-support@yourcompany.com.

---

## Week 1: Getting Set Up

### Day 1 Checklist

Your People Operations team member will meet you for a 1-hour orientation call
(remote) or in-person session covering:

- [ ] Complete I-9 verification with HR
- [ ] Receive and activate your company email (firstname.lastname@yourcompany.com)
- [ ] Set up Multi-Factor Authentication (MFA) on your Google account (required)
- [ ] Install 1Password (IT will send an invitation link) — all passwords must
      be stored in 1Password, never in browser or plain text
- [ ] Log into Slack and join your team channels
- [ ] Complete your Workday profile (photo, emergency contact, tax forms)
- [ ] Submit your laptop serial number to IT via the #it-help Slack channel
- [ ] Read and sign the Employee Handbook acknowledgment in DocuSign

### Software Accounts to Set Up

By the end of week 1, you should have access to all of the following. If any
are missing, contact it-support@yourcompany.com:

| Tool           | Purpose                          | Setup Method        |
|----------------|----------------------------------|---------------------|
| Google Workspace | Email, Calendar, Drive, Meet  | Auto-provisioned    |
| Slack          | Team communication               | Email invitation    |
| GitHub         | Code repository                  | IT request ticket   |
| Jira           | Project management               | Auto-provisioned    |
| Confluence     | Internal wiki / documentation    | Auto-provisioned    |
| 1Password      | Password manager                 | Email invitation    |
| Lattice        | Performance reviews              | Auto-provisioned    |
| BambooHR       | HR system (PTO, benefits)        | Email invitation    |
| Workday        | Payroll, expenses, time tracking | Auto-provisioned    |
| Expensify      | Expense reimbursement            | Email invitation    |
| PagerDuty      | On-call (engineering only)       | Engineering manager |

### Your Development Environment (Engineering Roles)

Follow the Engineering Setup Guide in Confluence for detailed instructions.
High-level summary:

```bash
# 1. Clone the main application repository
git clone git@github.com:yourcompany/main-app.git

# 2. Install dependencies
composer install && npm install

# 3. Start the local development environment
docker compose up -d

# 4. Run database migrations
php artisan migrate

# 5. Run the test suite to confirm everything is working
php artisan test

# 6. Open the app
open http://localhost:8000
```

Issues setting up your dev environment? Post in the #dev-environment Slack
channel with the error message — the team is always happy to help.

---

## Week 2: Learning the Codebase and Processes

### Codebase Orientation

Your engineering manager will schedule a 2-hour "codebase tour" with a senior
engineer who will walk through:
- Repository structure and key directories
- Core data models and their relationships
- How a typical API request flows through the system
- The testing strategy and how to run tests
- Deployment pipeline and how to read CI/CD output

After the tour, complete at least 2 "starter tasks" from the onboarding Jira
board. These are intentionally scoped to a single file or function to help you
get comfortable making changes and going through code review.

### Code Review Culture

We practice collaborative code review. Key expectations:
- **All code is reviewed**: No exceptions, including hotfixes (get async review)
- **Review within 24 hours**: If you're asked to review, respond within one
  business day. Use the Slack `/remind` command to set a reminder if needed.
- **Be kind, be specific**: Comment on the code, not the person. Always suggest
  the alternative when requesting a change ("What about using X instead, because...")
- **Approve when it's good enough**: Don't block on style preferences. Use
  "Nit:" prefix for optional suggestions.

### Architecture Decision Records (ADRs)

Major technical decisions are documented as ADRs in Confluence under
"Engineering > Architecture Decisions". Before making a significant change to
architecture, infrastructure, or shared libraries, write an ADR and get it
reviewed by at least two senior engineers.

---

## Week 3–4: Contributing and Connecting

### Your First Pull Request

By the end of week 2, you should have opened your first pull request. Your
onboarding buddy will review it and leave constructive comments. Don't worry
about getting everything perfect — the goal is to get familiar with the process.

**PR title format**: `[JIRA-123] Brief description of what changed`

**PR description must include:**
- What changed and why
- How to test it (steps to reproduce the feature or verify the fix)
- Screenshots for UI changes
- Any database migrations and whether they are backward-compatible
- Risk level: Low / Medium / High

### Team Rituals

By the end of your first month, you should have attended:

| Ritual                  | Frequency    | When                      |
|-------------------------|--------------|---------------------------|
| Engineering standup      | Daily        | 9:30 AM ET, #dev-standup  |
| Sprint planning          | Bi-weekly    | Monday 10 AM ET           |
| Sprint retrospective     | Bi-weekly    | Friday 3 PM ET            |
| Engineering all-hands    | Monthly      | 1st Thursday, 3 PM ET     |
| 1:1 with manager         | Weekly       | Scheduled by manager      |
| Onboarding buddy check-in | Weekly     | Scheduled by buddy        |

### 30-Day Onboarding Goals

At the end of your first 30 days, your manager will check in on the following:

1. **Technical Setup**: All software accounts active, dev environment running,
   test suite passing locally.

2. **First Contribution**: At least one PR merged to the main codebase.

3. **Process Familiarity**: Understands the deployment process, code review
   expectations, and how to escalate blockers.

4. **Team Integration**: Has met every member of the immediate team (1:1 intro
   calls scheduled via Calendly links shared in #team-intros).

5. **Documentation**: Has identified and fixed at least one documentation gap
   in Confluence (stale content, missing steps, unclear explanation).

---

## Key Resources and Links

### Internal Contacts

| Role                    | Name          | Slack Handle       |
|-------------------------|---------------|--------------------|
| HR / People Ops         | Jamie Smith   | @jamie.smith       |
| IT Support              | Chris Lee     | @chris.lee (or #it-help) |
| Your Onboarding Buddy   | (see email)   | (see email)        |
| Engineering Manager     | (see offer letter) | (see email)   |

### Important Slack Channels to Join

| Channel                 | Purpose                                      |
|-------------------------|----------------------------------------------|
| #announcements          | Company-wide announcements                   |
| #engineering            | Engineering team discussion                  |
| #dev-environment        | Dev setup help and tips                      |
| #deployments            | Deployment notifications                     |
| #incidents              | Production incident coordination             |
| #engineering-oncall     | On-call schedule and handoffs                |
| #learning               | Book club, talks, courses, articles          |
| #random                 | Off-topic team chat                          |

### Key Documents (Confluence)

- Engineering Setup Guide: confluence.internal/eng-setup
- Architecture Overview: confluence.internal/architecture
- API Documentation: confluence.internal/api-docs
- Security Policy: confluence.internal/security
- Data Handling Policy: confluence.internal/data-policy
- Architecture Decision Records: confluence.internal/adr
