# Contact Page Design Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a clean institutional contact page for SODAP-CI with polished hover and focus effects.

**Architecture:** Keep the site static and page-scoped. `contact.html` carries the markup and contact-specific CSS, while `tools/check-contact-page.mjs` verifies the expected content and UX hooks.

**Tech Stack:** Static HTML, Tailwind utility CSS, Bootstrap-compatible form markup, Font Awesome, Bootstrap Icons, Node verification scripts.

---

### Task 1: Add Contact Page Verification

**Files:**
- Create: `tools/check-contact-page.mjs`
- Modify: `package.json`

- [ ] Create a Node script that reads `contact.html` and asserts French institutional copy, contact cards, form fields, hover CSS hooks, map framing, and no template placeholder copy.
- [ ] Add `check:contact` to `package.json`.
- [ ] Run `npm run check:contact` and confirm it fails before implementation because the current page still has template content.

### Task 2: Redesign Contact Markup

**Files:**
- Modify: `contact.html`

- [ ] Replace the template contact section with the institutional two-column layout.
- [ ] Add four contact cards for address, email, phone, and hours.
- [ ] Add a polished form panel with French labels and a strong CTA.
- [ ] Add a framed map section with a floating information card.

### Task 3: Add Page-Specific Effects

**Files:**
- Modify: `contact.html`

- [ ] Add contact-specific CSS for background atmosphere, hover lift, icon motion, form focus, and CTA shine.
- [ ] Add responsive CSS so the layout stacks cleanly on mobile.

### Task 4: Verify

**Files:**
- Run only.

- [ ] Run `npm run check:contact`.
- [ ] Run `npm run check`.
- [ ] Run `npm run build:css` if generated CSS needs to reflect changed class usage.
- [ ] Inspect `git diff -- contact.html package.json tools/check-contact-page.mjs docs/superpowers/specs/2026-05-26-contact-page-design.md docs/superpowers/plans/2026-05-26-contact-page-design.md`.
