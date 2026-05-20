# Apropos Tailwind Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `apropos.html` into a clean Tailwind-based About page with a strong hero, mission/vision section, and values grid aligned with the new SODAP-CI visual system.

**Architecture:** Keep the shared topbar, sticky navigation, spinner, footer, and existing helper JavaScript intact while replacing the page body with three focused sections. Reuse existing Tailwind component classes from `src/input.css` and the already-compiled `css/tailwind.css` so the page stays visually consistent with `index.html` and `service.html`.

**Tech Stack:** Static HTML, Tailwind CSS, existing `js/tailwind-secondary.js`, WOW.js, Font Awesome, Bootstrap Icons

---

### Task 1: Replace legacy page content with the new About structure

**Files:**
- Modify: `apropos.html`

- [ ] **Step 1: Inspect the current Tailwind patterns used by migrated pages**

Read and mirror the shared shell structure already used in:

- `index.html`
- `service.html`

Focus on:

- topbar markup
- sticky navigation markup
- hero section spacing
- shared CTA classes
- footer/back-to-top structure

- [ ] **Step 2: Remove legacy template sections from the About page**

Delete the legacy sections that make the page feel inconsistent:

- old Bootstrap page header
- old About block
- `Features`
- `Team`
- `Newsletter`
- legacy footer/copyright markup if it is not aligned with the Tailwind pages

Expected result:

- only shared shell elements remain
- the page body is ready for a Tailwind rebuild

- [ ] **Step 3: Write the new hero section**

Add a hero section using this content:

```html
<header class="relative overflow-hidden bg-sodap-600 wow fadeIn" data-wow-delay="0.1s">
  <div class="absolute inset-0 bg-[url('../img/carousel-2.JPG')] bg-cover bg-center opacity-20"></div>
  <div class="absolute inset-0 bg-black/60"></div>
  <div class="site-container relative py-16 md:py-20 lg:py-24">
    <div class="max-w-3xl">
      <p class="mb-4 inline-flex rounded-full border border-white/30 bg-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-white/85">
        Entreprise agropastorale &amp; environnementale
      </p>
      <h1 class="mb-6 font-roboto text-4xl font-extrabold leading-tight text-white md:text-6xl">
        Redonner vie aux terres, construire un avenir durable
      </h1>
      <div class="space-y-4 text-base text-white/90 md:text-lg">
        <p>...</p>
      </div>
      <div class="mt-8 flex flex-col gap-4 sm:flex-row">
        <a href="service.html" class="btn btn-primary px-6 py-4">Découvrir nos services</a>
        <a href="contact.html" class="btn border border-white/30 bg-white/10 px-6 py-4 text-white hover:bg-white hover:text-sodap-600">Nous contacter</a>
      </div>
    </div>
  </div>
</header>
```

- [ ] **Step 4: Write the mission/vision section**

Add a section with:

- one intro block
- one image block using `img/about.JPG`
- two cards: `Notre mission` and `Notre vision`
- three small markers: `Environnement`, `Agriculture durable`, `Innovation terrain`

Use a grid such as:

```html
<section class="section-y bg-sodap-soft/??">
  <div class="site-container grid gap-10 lg:grid-cols-[0.95fr_1.05fr]">
    ...
  </div>
</section>
```

Implementation note:

- If utility opacity variants for `bg-sodap-soft` are not available, use `bg-sodap-soft` or `bg-gray-50`.

- [ ] **Step 5: Write the values section**

Add:

- title
- intro paragraph
- five value cards

Value card content:

```text
Responsabilité environnementale
Innovation
Qualité
Intégrité
Engagement communautaire
```

Each card should contain:

- icon container
- heading
- short paragraph

- [ ] **Step 6: Reattach the shared footer and back-to-top button**

Use the existing Tailwind-style footer pattern already present in `service.html` so the page stays consistent with the migrated pages.

- [ ] **Step 7: Commit**

```bash
git add apropos.html
git commit -m "feat: rebuild apropos page with tailwind"
```

Do not run this commit if the user has not asked for git commits yet.

### Task 2: Ensure the new HTML only uses supported shared classes

**Files:**
- Modify: `apropos.html`
- Optional modify: `src/input.css`

- [ ] **Step 1: Compare all new utility classes against the compiled Tailwind output**

Run:

```bash
rg -n "space-y-|ring-|backdrop-|from-|to-|via-|max-w-4xl|xl:grid-cols-3|sm:flex-row" apropos.html
```

Expected:

- any class not already generated in `css/tailwind.css` is identified before verification

- [ ] **Step 2: Simplify unsupported classes if needed**

If a class is missing from the compiled stylesheet, replace it with an already-used equivalent rather than growing scope. For example:

- replace `space-y-4` with repeated `mb-4` paragraphs
- replace complex gradients with overlay divs
- replace unsupported responsive variants with available `sm:` / `md:` / `lg:` variants already in the build

- [ ] **Step 3: Rebuild Tailwind only if new classes are intentionally introduced**

Run:

```bash
npm run build:css
```

Expected:

- `css/tailwind.css` rebuilds successfully

- [ ] **Step 4: Commit**

```bash
git add apropos.html src/input.css css/tailwind.css
git commit -m "style: align apropos page utilities with tailwind build"
```

Do not run this commit if the user has not asked for git commits yet.

### Task 3: Verify structure and regressions

**Files:**
- Verify: `apropos.html`

- [ ] **Step 1: Run the existing project checks**

Run:

```bash
npm run check
```

Expected:

- existing Tailwind setup checks pass

- [ ] **Step 2: Review the final About page structure**

Run:

```bash
rg -n "<header|Notre mission|Notre vision|Les valeurs qui guident nos actions|Découvrir nos services|Nous contacter" apropos.html
```

Expected:

- all required content anchors are present once

- [ ] **Step 3: Inspect git diff for only intended file changes**

Run:

```bash
git diff -- apropos.html src/input.css css/tailwind.css
```

Expected:

- the diff shows the About-page rewrite and any minimal style support changes only

- [ ] **Step 4: Commit**

```bash
git add apropos.html src/input.css css/tailwind.css
git commit -m "test: verify apropos tailwind rebuild"
```

Do not run this commit if the user has not asked for git commits yet.
