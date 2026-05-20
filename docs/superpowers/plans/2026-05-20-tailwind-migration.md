# Tailwind Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local Tailwind CSS toolchain and migrate `index.html` as the first Bootstrap-free pilot page.

**Architecture:** Keep the existing static HTML site shape. Add Tailwind CLI v4 locally, compile `src/input.css` to `css/tailwind.css`, and move the pilot page interactions into `js/tailwind-main.js` so legacy Bootstrap pages can keep using `js/main.js` during the transition.

**Tech Stack:** Static HTML, Tailwind CSS CLI v4, npm scripts, vanilla JavaScript, existing jQuery/WOW/OwlCarousel libraries where still needed.

---

## Reference Docs

- Tailwind CLI v4.3 installation: https://tailwindcss.com/docs/installation/tailwind-cli
- Tailwind source detection and `@source`: https://tailwindcss.com/docs/detecting-classes-in-source-files
- Tailwind theme variables and `@theme`: https://tailwindcss.com/docs/theme
- Tailwind component layer: https://tailwindcss.com/docs/adding-custom-styles

## File Structure

- Create `package.json`: local npm scripts and Tailwind dev dependencies.
- Create `tailwind.config.js`: lightweight project configuration for editor compatibility and documented scan paths.
- Create `src/input.css`: Tailwind import, source registration, SODAP-CI design tokens, reusable component classes, and migration-only styles.
- Create `css/tailwind.css`: compiled output consumed by `index.html`.
- Create `tools/check-tailwind-setup.mjs`: Node structural test for the Tailwind setup.
- Create `tools/check-index-tailwind-pilot.mjs`: Node structural test for the Bootstrap-free pilot page.
- Create `js/tailwind-main.js`: Bootstrap-free spinner, sticky nav, mobile menu, dropdown, hero carousel, and back-to-top interactions for `index.html`.
- Modify `index.html`: replace Bootstrap CSS/JS references and Bootstrap classes/attributes with Tailwind/component classes.
- Do not modify the other HTML pages in this phase.
- Do not delete `css/bootstrap.min.css`, `scss/bootstrap`, or `js/main.js` in this phase.

## Task 1: Add Tailwind Setup Guardrails

**Files:**
- Create: `tools/check-tailwind-setup.mjs`
- Later modify: `package.json`
- Later modify: `tailwind.config.js`
- Later modify: `src/input.css`

- [ ] **Step 1: Write the failing setup test**

Create `tools/check-tailwind-setup.mjs`:

```js
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();

function read(filePath) {
  return fs.readFileSync(path.join(root, filePath), "utf8");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

assert(fs.existsSync(path.join(root, "package.json")), "package.json is required");
assert(fs.existsSync(path.join(root, "tailwind.config.js")), "tailwind.config.js is required");
assert(fs.existsSync(path.join(root, "src", "input.css")), "src/input.css is required");

const pkg = JSON.parse(read("package.json"));
assert(pkg.private === true, "package.json must be private");
assert(pkg.scripts?.["build:css"] === "npx @tailwindcss/cli -i ./src/input.css -o ./css/tailwind.css --minify", "build:css script must compile Tailwind to css/tailwind.css");
assert(pkg.scripts?.["watch:css"] === "npx @tailwindcss/cli -i ./src/input.css -o ./css/tailwind.css --watch", "watch:css script must watch Tailwind");
assert(pkg.scripts?.["check:setup"] === "node tools/check-tailwind-setup.mjs", "check:setup script must run this test");
assert(pkg.scripts?.["check:index"] === "node tools/check-index-tailwind-pilot.mjs", "check:index script must be reserved for the pilot test");
assert(pkg.devDependencies?.tailwindcss, "tailwindcss must be a devDependency");
assert(pkg.devDependencies?.["@tailwindcss/cli"], "@tailwindcss/cli must be a devDependency");

const inputCss = read("src/input.css");
assert(inputCss.includes('@import "tailwindcss";'), "src/input.css must import Tailwind");
assert(inputCss.includes('@source "../*.html";'), "src/input.css must scan root HTML files");
assert(inputCss.includes('@source "../js/**/*.js";'), "src/input.css must scan local JS files");
assert(inputCss.includes("--color-sodap-600: #214f21;"), "src/input.css must define the SODAP-CI primary color");
assert(inputCss.includes("--color-sodap-700: #1a3e1a;"), "src/input.css must define the SODAP-CI hover color");
assert(inputCss.includes("--font-poppins:"), "src/input.css must define the Poppins font token");
assert(inputCss.includes("@layer components"), "src/input.css must define reusable component classes");

const config = read("tailwind.config.js");
assert(config.includes("./*.html"), "tailwind.config.js must document root HTML scanning");
assert(config.includes("./js/**/*.js"), "tailwind.config.js must document JS scanning");
assert(config.includes("sodap"), "tailwind.config.js must document the SODAP-CI theme token");

console.log("Tailwind setup guardrails passed");
```

- [ ] **Step 2: Run the setup test and verify it fails**

Run:

```bash
node tools/check-tailwind-setup.mjs
```

Expected: FAIL with `package.json is required`.

- [ ] **Step 3: Add the local Tailwind npm setup**

Create `package.json`:

```json
{
  "name": "sodapci",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "build:css": "npx @tailwindcss/cli -i ./src/input.css -o ./css/tailwind.css --minify",
    "watch:css": "npx @tailwindcss/cli -i ./src/input.css -o ./css/tailwind.css --watch",
    "check:setup": "node tools/check-tailwind-setup.mjs",
    "check:index": "node tools/check-index-tailwind-pilot.mjs",
    "check": "npm run check:setup && npm run check:index"
  },
  "devDependencies": {
    "@tailwindcss/cli": "^4.3.0",
    "tailwindcss": "^4.3.0"
  }
}
```

Create `tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./*.html", "./js/**/*.js"],
  theme: {
    extend: {
      colors: {
        sodap: {
          600: "#214f21",
          700: "#1a3e1a"
        }
      },
      fontFamily: {
        poppins: ["Poppins", "sans-serif"],
        roboto: ["Roboto", "sans-serif"]
      }
    }
  }
};
```

Create `src/input.css`:

```css
@import "tailwindcss";
@source "../*.html";
@source "../js/**/*.js";

@theme {
  --color-sodap-600: #214f21;
  --color-sodap-700: #1a3e1a;
  --color-sodap-soft: #f3f8f1;
  --font-poppins: "Poppins", sans-serif;
  --font-roboto: "Roboto", sans-serif;
}

@layer base {
  html {
    scroll-behavior: smooth;
  }

  body {
    font-family: var(--font-poppins);
    color: #555;
    background: #fff;
  }

  h1,
  h2,
  h3,
  h4,
  h5,
  h6 {
    font-family: var(--font-roboto);
    color: #222;
    line-height: 1.15;
  }

  img {
    max-width: 100%;
    height: auto;
  }
}

@layer components {
  .site-container {
    width: min(100% - 2rem, 1140px);
    margin-inline: auto;
  }

  .section-y {
    padding-block: 5rem;
  }

  .section-top {
    padding-top: 5rem;
  }

  .section-bottom {
    padding-bottom: 5rem;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-weight: 600;
    text-transform: uppercase;
    transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, transform 0.3s ease;
  }

  .btn-primary {
    background: var(--color-sodap-600);
    color: #fff;
    border: 1px solid var(--color-sodap-600);
  }

  .btn-primary:hover {
    background: var(--color-sodap-700);
    border-color: var(--color-sodap-700);
    transform: translateY(-1px);
  }

  .btn-light {
    background: #fff;
    color: var(--color-sodap-600);
    border: 1px solid #fff;
  }

  .btn-square,
  .btn-sm-square,
  .btn-lg-square,
  .btn-xl-square,
  .btn-xxl-square {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    padding: 0;
  }

  .btn-square { width: 38px; height: 38px; }
  .btn-sm-square { width: 32px; height: 32px; }
  .btn-lg-square { width: 48px; height: 48px; }
  .btn-xl-square { width: 60px; height: 60px; }
  .btn-xxl-square { width: 75px; height: 75px; }

  .nav-link {
    display: block;
    padding-block: 0.7rem;
    color: #222;
    font-size: 1rem;
    font-weight: 600;
    text-transform: uppercase;
    transition: color 0.3s ease;
  }

  .nav-link:hover,
  .nav-link.active {
    color: var(--color-sodap-600);
  }

  .feature-card {
    height: 100%;
    border: 1px solid rgb(0 0 0 / 0.08);
    background: #fff;
    transition: box-shadow 0.3s ease, transform 0.3s ease;
  }

  .feature-card:hover {
    box-shadow: 0 16px 35px rgb(0 0 0 / 0.08);
    transform: translateY(-4px);
  }

  .form-field {
    width: 100%;
    border: 0;
    background: #f8f9fa;
    padding: 1rem;
    min-height: 56px;
    outline: none;
  }

  .form-field:focus {
    box-shadow: 0 0 0 0.25rem rgb(33 79 33 / 0.25);
  }
}
```

- [ ] **Step 4: Install dependencies**

Run:

```bash
npm install
```

Expected: npm creates `package-lock.json` and installs `tailwindcss` plus `@tailwindcss/cli`.

- [ ] **Step 5: Run the setup test and verify it passes**

Run:

```bash
npm run check:setup
```

Expected: PASS with `Tailwind setup guardrails passed`.

- [ ] **Step 6: Build CSS**

Run:

```bash
npm run build:css
```

Expected: exit code 0 and `css/tailwind.css` exists.

- [ ] **Step 7: Commit the setup**

```bash
git add package.json package-lock.json tailwind.config.js src/input.css css/tailwind.css tools/check-tailwind-setup.mjs
git commit -m "build: add local tailwind css pipeline"
```

## Task 2: Add Pilot Page Guardrails

**Files:**
- Create: `tools/check-index-tailwind-pilot.mjs`
- Later modify: `index.html`
- Later create: `js/tailwind-main.js`

- [ ] **Step 1: Write the failing pilot test**

Create `tools/check-index-tailwind-pilot.mjs`:

```js
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();

function read(filePath) {
  return fs.readFileSync(path.join(root, filePath), "utf8");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const index = read("index.html");
const pilotJsExists = fs.existsSync(path.join(root, "js", "tailwind-main.js"));
const cssExists = fs.existsSync(path.join(root, "css", "tailwind.css"));

assert(cssExists, "css/tailwind.css must be built");
assert(pilotJsExists, "js/tailwind-main.js is required for Bootstrap-free interactions");
assert(index.includes('href="css/tailwind.css"'), "index.html must load css/tailwind.css");
assert(!index.includes('href="css/bootstrap.min.css"'), "index.html must not load Bootstrap CSS");
assert(!index.includes("bootstrap.bundle"), "index.html must not load Bootstrap JS");
assert(!index.includes("data-bs-"), "index.html must not use Bootstrap data attributes");
assert(!index.includes("navbar-toggler"), "index.html must not use Bootstrap navbar toggler classes");
assert(!index.includes("navbar-collapse"), "index.html must not use Bootstrap collapse classes");
assert(!index.includes("carousel "), "index.html must not use Bootstrap carousel classes");
assert(index.includes('data-mobile-menu-toggle'), "index.html must expose a mobile menu toggle");
assert(index.includes('data-mobile-menu'), "index.html must expose the mobile menu container");
assert(index.includes('data-projects-toggle'), "index.html must expose the project dropdown toggle");
assert(index.includes('data-projects-menu'), "index.html must expose the project dropdown menu");
assert(index.includes('data-hero-carousel'), "index.html must expose the hero carousel root");
assert(index.includes('data-hero-slide'), "index.html must expose hero slides");
assert(index.includes('data-hero-indicator'), "index.html must expose hero indicators");
assert(index.includes('src="js/tailwind-main.js"'), "index.html must load js/tailwind-main.js");

const pilotJs = read("js/tailwind-main.js");
assert(pilotJs.includes("initMobileMenu"), "tailwind-main.js must initialize the mobile menu");
assert(pilotJs.includes("initProjectsDropdown"), "tailwind-main.js must initialize the project dropdown");
assert(pilotJs.includes("initHeroCarousel"), "tailwind-main.js must initialize the hero carousel");
assert(pilotJs.includes("initBackToTop"), "tailwind-main.js must initialize the back-to-top button");

console.log("Index Tailwind pilot guardrails passed");
```

- [ ] **Step 2: Run the pilot test and verify it fails**

Run:

```bash
npm run check:index
```

Expected: FAIL with `js/tailwind-main.js is required for Bootstrap-free interactions`.

- [ ] **Step 3: Commit the failing guardrail test**

```bash
git add tools/check-index-tailwind-pilot.mjs package.json
git commit -m "test: add tailwind pilot guardrails"
```

## Task 3: Add Bootstrap-Free Pilot JavaScript

**Files:**
- Create: `js/tailwind-main.js`
- Modify only if needed: `tools/check-index-tailwind-pilot.mjs`

- [ ] **Step 1: Add the minimal local interaction script**

Create `js/tailwind-main.js`:

```js
(function () {
  "use strict";

  function qs(selector, root = document) {
    return root.querySelector(selector);
  }

  function qsa(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function initSpinner() {
    const spinner = qs("#spinner");
    if (!spinner) return;

    window.setTimeout(function () {
      spinner.classList.remove("show", "opacity-100", "visible");
      spinner.classList.add("opacity-0", "invisible");
    }, 100);
  }

  function initWow() {
    if (window.WOW) {
      new window.WOW().init();
    }
  }

  function initStickyNav() {
    const nav = qs("[data-sticky-nav]");
    if (!nav) return;

    function updateNav() {
      if (window.scrollY > 300) {
        nav.classList.add("shadow-sm", "top-0");
        nav.classList.remove("-top-24");
      } else {
        nav.classList.remove("shadow-sm", "top-0");
        nav.classList.add("-top-24");
      }
    }

    updateNav();
    window.addEventListener("scroll", updateNav, { passive: true });
  }

  function initMobileMenu() {
    const toggle = qs("[data-mobile-menu-toggle]");
    const menu = qs("[data-mobile-menu]");
    if (!toggle || !menu) return;

    function setOpen(isOpen) {
      toggle.setAttribute("aria-expanded", String(isOpen));
      menu.classList.toggle("hidden", !isOpen);
    }

    toggle.addEventListener("click", function () {
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    window.addEventListener("resize", function () {
      if (window.innerWidth >= 1024) {
        setOpen(true);
      } else {
        setOpen(false);
      }
    });

    setOpen(window.innerWidth >= 1024);
  }

  function initProjectsDropdown() {
    const toggle = qs("[data-projects-toggle]");
    const menu = qs("[data-projects-menu]");
    if (!toggle || !menu) return;

    function setOpen(isOpen) {
      toggle.setAttribute("aria-expanded", String(isOpen));
      menu.classList.toggle("hidden", !isOpen);
    }

    toggle.addEventListener("click", function (event) {
      event.preventDefault();
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    document.addEventListener("click", function (event) {
      if (!toggle.contains(event.target) && !menu.contains(event.target)) {
        setOpen(false);
      }
    });

    setOpen(false);
  }

  function initHeroCarousel() {
    const root = qs("[data-hero-carousel]");
    if (!root) return;

    const slides = qsa("[data-hero-slide]", root);
    const indicators = qsa("[data-hero-indicator]", root);
    if (slides.length === 0) return;

    let activeIndex = Math.max(0, slides.findIndex((slide) => slide.dataset.active === "true"));
    let timer = null;

    function showSlide(index) {
      activeIndex = (index + slides.length) % slides.length;

      slides.forEach(function (slide, slideIndex) {
        const isActive = slideIndex === activeIndex;
        slide.classList.toggle("opacity-100", isActive);
        slide.classList.toggle("opacity-0", !isActive);
        slide.classList.toggle("pointer-events-auto", isActive);
        slide.classList.toggle("pointer-events-none", !isActive);
        slide.dataset.active = String(isActive);
      });

      indicators.forEach(function (indicator, indicatorIndex) {
        indicator.classList.toggle("border-sodap-600", indicatorIndex === activeIndex);
        indicator.classList.toggle("border-white", indicatorIndex !== activeIndex);
        indicator.setAttribute("aria-current", indicatorIndex === activeIndex ? "true" : "false");
      });
    }

    function restart() {
      window.clearInterval(timer);
      timer = window.setInterval(function () {
        showSlide(activeIndex + 1);
      }, 5500);
    }

    indicators.forEach(function (indicator, index) {
      indicator.addEventListener("click", function () {
        showSlide(index);
        restart();
      });
    });

    showSlide(activeIndex);
    restart();
  }

  function initBackToTop() {
    const button = qs(".back-to-top");
    if (!button) return;

    function updateButton() {
      button.classList.toggle("hidden", window.scrollY <= 300);
      button.classList.toggle("inline-flex", window.scrollY > 300);
    }

    button.addEventListener("click", function (event) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: "smooth" });
    });

    updateButton();
    window.addEventListener("scroll", updateButton, { passive: true });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initSpinner();
    initWow();
    initStickyNav();
    initMobileMenu();
    initProjectsDropdown();
    initHeroCarousel();
    initBackToTop();
  });
})();
```

- [ ] **Step 2: Run the pilot test and verify the next expected failure**

Run:

```bash
npm run check:index
```

Expected: FAIL with `index.html must load css/tailwind.css`.

- [ ] **Step 3: Commit the pilot JavaScript**

```bash
git add js/tailwind-main.js
git commit -m "feat: add bootstrap-free pilot interactions"
```

## Task 4: Migrate `index.html` Head, Loader, Topbar, Navbar, and Hero

**Files:**
- Modify: `index.html`

- [ ] **Step 1: Replace the CSS links in `<head>`**

Change:

```html
<!-- Customized Bootstrap Stylesheet -->
<link href="css/bootstrap.min.css" rel="stylesheet">

<!-- Template Stylesheet -->
<link href="css/style.css" rel="stylesheet">
```

To:

```html
<!-- Tailwind Stylesheet -->
<link href="css/tailwind.css" rel="stylesheet">

<!-- Template Stylesheet kept during migration -->
<link href="css/style.css" rel="stylesheet">
```

- [ ] **Step 2: Replace the spinner markup**

Use this structure:

```html
<div id="spinner"
    class="show fixed left-1/2 top-1/2 z-[99999] flex h-screen w-full -translate-x-1/2 -translate-y-1/2 items-center justify-center bg-white opacity-100 visible transition-opacity duration-500">
    <div class="h-12 w-12 animate-spin rounded-full border-4 border-sodap-600 border-t-transparent" role="status"
        aria-label="Chargement"></div>
</div>
```

- [ ] **Step 3: Replace the topbar wrapper and utility classes**

Use Tailwind classes while preserving the existing logo and text:

```html
<div class="hidden bg-sodap-600 text-white lg:flex wow fadeIn" data-wow-delay="0.1s">
    <div class="site-container py-3">
        <div class="flex items-center">
            <a href="index.html">
                <img src="img/logo.jpeg" alt="Logo" class="h-[50px] w-auto">
            </a>
            <div class="ml-auto flex items-center gap-6 text-sm">
                <small><i class="fa fa-map-marker-alt mr-3"></i>Yamoussoukro, quartier 50 logements,derrière l'école méthodiste</small>
                <small><i class="fa fa-envelope mr-3"></i>lasodapci@gmail.com</small>
                <small><i class="fa fa-phone-alt mr-3"></i>+225 27-33-75-73-12 // 21-21-80-58-73</small>
                <div class="flex gap-2">
                    <a class="btn btn-sm-square btn-light" href=""><i class="fab fa-facebook-f"></i></a>
                    <a class="btn btn-sm-square btn-light" href=""><i class="fab fa-twitter"></i></a>
                    <a class="btn btn-sm-square btn-light" href=""><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Replace the navbar with Bootstrap-free markup**

Use this structure:

```html
<div data-sticky-nav class="sticky top-0 z-50 bg-white transition-all duration-500 wow fadeIn" data-wow-delay="0.1s">
    <div class="site-container">
        <nav class="flex min-h-[74px] items-center bg-white">
            <a href="index.html" class="lg:hidden">
                <img src="img/logo.jpeg" alt="Logo" class="h-10 w-auto">
            </a>
            <button type="button"
                class="ml-auto inline-flex h-11 w-11 items-center justify-center border border-gray-200 text-sodap-600 lg:hidden"
                data-mobile-menu-toggle aria-controls="siteNavigation" aria-expanded="false" aria-label="Ouvrir le menu">
                <span class="sr-only">Menu</span>
                <i class="fa fa-bars text-xl"></i>
            </button>
            <div id="siteNavigation" data-mobile-menu class="hidden w-full flex-col border-t border-gray-100 py-4 lg:flex lg:flex-row lg:items-center lg:border-0 lg:py-0">
                <div class="flex flex-col lg:flex-row lg:items-center lg:gap-9">
                    <a href="index.html" class="nav-link active">Accueil</a>
                    <a href="apropos.html" class="nav-link">À Propos</a>
                    <a href="service.html" class="nav-link">Services</a>
                    <div class="relative">
                        <a href="#" class="nav-link inline-flex items-center gap-2" data-projects-toggle aria-expanded="false">
                            Projets <i class="fa fa-chevron-down text-xs"></i>
                        </a>
                        <div data-projects-menu class="hidden min-w-52 bg-gray-50 py-2 shadow-lg lg:absolute lg:left-0 lg:top-full">
                            <a href="reboisement.html" class="block px-5 py-2 text-sm text-gray-700 hover:bg-white hover:text-sodap-600">Reboisement</a>
                            <a href="elevage.html" class="block px-5 py-2 text-sm text-gray-700 hover:bg-white hover:text-sodap-600">Élevage</a>
                            <a href="agriculture.html" class="block px-5 py-2 text-sm text-gray-700 hover:bg-white hover:text-sodap-600">Agriculture</a>
                            <a href="pisciculture.html" class="block px-5 py-2 text-sm text-gray-700 hover:bg-white hover:text-sodap-600">Pisciculture</a>
                        </div>
                    </div>
                    <a href="contact.html" class="nav-link">Contact</a>
                </div>
                <div class="mt-4 lg:ml-auto lg:mt-0">
                    <a href="" class="btn btn-primary px-4 py-2">Obtenir un Devis</a>
                </div>
            </div>
        </nav>
    </div>
</div>
```

- [ ] **Step 5: Replace the Bootstrap carousel with a Tailwind hero carousel**

Use this structure and keep the existing three image/text pairs:

```html
<div class="mb-20 wow fadeIn" data-wow-delay="0.1s">
    <div data-hero-carousel class="relative min-h-[500px] overflow-hidden lg:min-h-[660px]">
        <div class="absolute inset-y-0 left-6 z-20 hidden flex-col justify-center gap-3 md:flex">
            <button type="button" data-hero-indicator class="h-[70px] w-[70px] overflow-hidden border-2 border-sodap-600" aria-current="true" aria-label="Slide 1">
                <img class="h-full w-full object-cover" src="img/carousel-1.jpg" alt="Aperçu du développement agricole">
            </button>
            <button type="button" data-hero-indicator class="h-[70px] w-[70px] overflow-hidden border-2 border-white" aria-current="false" aria-label="Slide 2">
                <img class="h-full w-full object-cover" src="img/carousel-2.jpg" alt="Aperçu de la restauration des terres">
            </button>
            <button type="button" data-hero-indicator class="h-[70px] w-[70px] overflow-hidden border-2 border-white" aria-current="false" aria-label="Slide 3">
                <img class="h-full w-full object-cover" src="img/carousel-3.jpg" alt="Aperçu des sites restaurés">
            </button>
        </div>

        <div data-hero-slide data-active="true" class="absolute inset-0 opacity-100 transition-opacity duration-700 pointer-events-auto">
            <img class="h-full w-full object-cover" src="img/carousel-1.jpg" alt="Image">
            <div class="absolute inset-0 flex items-center bg-black/70 px-8 md:pl-36 lg:pl-44">
                <div class="max-w-5xl">
                    <h1 class="animated zoomIn mb-8 font-roboto text-4xl font-extrabold uppercase leading-tight text-white md:text-6xl lg:text-7xl">Développer l’agriculture, préserver les ressources naturelles</h1>
                    <a href="#" class="btn btn-primary px-6 py-4">Voir plus</a>
                </div>
            </div>
        </div>

        <div data-hero-slide data-active="false" class="absolute inset-0 opacity-0 transition-opacity duration-700 pointer-events-none">
            <img class="h-full w-full object-cover" src="img/carousel-2.jpg" alt="Image">
            <div class="absolute inset-0 flex items-center bg-black/70 px-8 md:pl-36 lg:pl-44">
                <div class="max-w-5xl">
                    <h1 class="animated zoomIn mb-8 font-roboto text-4xl font-extrabold uppercase leading-tight text-white md:text-6xl lg:text-7xl">Valoriser la terre, restaurer</h1>
                    <a href="#" class="btn btn-primary px-6 py-4">Voir plus</a>
                </div>
            </div>
        </div>

        <div data-hero-slide data-active="false" class="absolute inset-0 opacity-0 transition-opacity duration-700 pointer-events-none">
            <img class="h-full w-full object-cover" src="img/carousel-3.jpg" alt="Image">
            <div class="absolute inset-0 flex items-center bg-black/70 px-8 md:pl-36 lg:pl-44">
                <div class="max-w-5xl">
                    <h1 class="animated zoomIn mb-8 font-roboto text-4xl font-extrabold uppercase leading-tight text-white md:text-6xl lg:text-7xl">Transformer les sites exploités en opportunités durables</h1>
                    <a href="#" class="btn btn-primary px-6 py-4">Voir plus</a>
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Run the pilot test and verify the next expected failure**

Run:

```bash
npm run check:index
```

Expected: FAIL caused by remaining Bootstrap classes/references later in `index.html`, not by the head, navbar, or hero.

- [ ] **Step 7: Commit the first HTML migration slice**

```bash
git add index.html
git commit -m "feat: migrate index header to tailwind"
```

## Task 5: Migrate Remaining `index.html` Sections

**Files:**
- Modify: `index.html`

- [ ] **Step 1: Replace section wrappers**

Use these class conversions throughout `index.html`:

```text
container-fluid pt-6 pb-6 -> section-y
container-fluid service pt-6 pb-6 -> section-y service
container-fluid appoinment mt-6 mb-6 py-5 -> my-20 py-20 appoinment
container py-5 -> site-container py-20
container pt-4 -> site-container pt-6
container -> site-container
row g-5 -> grid gap-12 lg:grid-cols-2
row g-4 -> grid gap-6 md:grid-cols-2 lg:grid-cols-4
row g-0 feature-row -> grid gap-0 md:grid-cols-2 lg:grid-cols-4 shadow-[0_0_45px_rgb(0_0_0_/_0.08)]
col-lg-6 -> min-w-0
col-lg-3 col-md-6 -> min-w-0
col-md-6 -> min-w-0
col-4 -> min-w-0
```

- [ ] **Step 2: Replace spacing and display utilities**

Use these class conversions where they appear:

```text
d-flex -> flex
align-items-center -> items-center
align-items-start -> items-start
justify-content-between -> justify-between
justify-content-center -> justify-center
text-center -> text-center
text-md-start -> md:text-left
text-md-end -> md:text-right
text-white -> text-white
text-light -> text-gray-100
text-body -> text-gray-600
text-primary -> text-sodap-600
bg-primary -> bg-sodap-600
bg-light -> bg-gray-100
bg-white -> bg-white
bg-dark -> bg-gray-950
mb-4 -> mb-6
mb-5 -> mb-10
mt-6 -> mt-20
pb-5 -> pb-10
pt-5 -> pt-10
p-5 -> p-8
px-5 -> px-8
py-5 -> py-20
me-3 -> mr-4
ms-3 -> ml-4
ms-auto -> ml-auto
w-100 -> w-full
h-100 -> h-full
img-fluid -> h-auto max-w-full
```

- [ ] **Step 3: Replace feature cards**

Use this wrapper for each activity card. Replace `DELAY`, `ICON`, `TITLE`, and `TEXT` with one row from the table after the snippet.

```html
<div class="wow fadeIn" data-wow-delay="DELAY">
    <div class="feature-card p-8">
        <div class="btn-xxl-square mb-6 -mt-14 bg-sodap-600 text-white">
            <i class="ICON"></i>
        </div>
        <h5 class="mb-4 font-roboto text-lg font-bold uppercase">TITLE</h5>
        <p class="mb-6">TEXT</p>
        <a class="relative z-10 flex justify-between text-sm font-semibold uppercase text-gray-600 hover:text-sodap-600" href="#">
            <b class="bg-white pr-4">Lire Plus</b>
            <i class="bi bi-arrow-right bg-white pl-4"></i>
        </a>
    </div>
</div>
```

Use these six rows:

| DELAY | ICON | TITLE | TEXT |
| --- | --- | --- | --- |
| `0.3s` | `fa fa-mountain fa-2x` | `Remblayage de parcelles minières` | `Réhabilitation écologique des sites miniers exploités pour restaurer la terre.` |
| `0.4s` | `fa fa-tree fa-2x` | `Reboisement & Réhabilitation environnementale` | `Plantation d’arbres et restauration des écosystèmes pour un avenir durable.` |
| `0.5s` | `fa fa-fish fa-2x` | `Pisciculture sur carrières profondes` | `Exploitation durable des carrières pour l’élevage de poissons.` |
| `0.6s` | `fa fa-drumstick-bite fa-2x` | `Élevage de volailles` | `Production de poulets de qualité dans une approche durable.` |
| `0.7s` | `fa fa-leaf fa-2x` | `Culture de banane` | `Développement agricole avec des plantations de bananes durables.` |
| `0.8s` | `fa fa-tractor fa-2x` | `Location de bulldozers` | `Mise à disposition d’engins lourds pour vos projets industriels.` |

- [ ] **Step 4: Replace project/service cards**

Use this wrapper for each project card. Replace `DELAY`, `IMAGE`, `TITLE`, and `TEXT` with one row from the table after the snippet.

```html
<div class="wow fadeInUp" data-wow-delay="DELAY">
    <div class="group relative mt-12 h-full bg-white shadow-[0_0_45px_rgb(0_0_0_/_0.05)]">
        <img class="-mt-12 ml-10 w-[calc(100%-2.5rem)] object-cover" src="IMAGE" alt="">
        <div class="px-8 pb-16 pt-6">
            <h5 class="mb-3 font-roboto text-lg font-bold uppercase">TITLE</h5>
            <p>TEXT</p>
        </div>
        <a class="btn btn-light absolute bottom-0 left-8 translate-y-1/2 px-4 py-2 shadow" href="">Read More<i class="bi bi-chevron-double-right ml-1"></i></a>
    </div>
</div>
```

Use these four rows:

| DELAY | IMAGE | TITLE | TEXT |
| --- | --- | --- | --- |
| `0.1s` | `img/service-1.jpg` | `Metal Works` | `Lorem ipsum dolor sit amet, consectetur adipiscing elit. Curabitur tellus augue.` |
| `0.2s` | `img/service-2.jpg` | `Steel welding` | `Lorem ipsum dolor sit amet, consectetur adipiscing elit. Curabitur tellus augue.` |
| `0.3s` | `img/service-3.jpg` | `pipe welding` | `Lorem ipsum dolor sit amet, consectetur adipiscing elit. Curabitur tellus augue.` |
| `0.4s` | `img/service-4.jpg` | `Custom welding` | `Lorem ipsum dolor sit amet, consectetur adipiscing elit. Curabitur tellus augue.` |

- [ ] **Step 5: Replace the contact form area**

Use Tailwind grids and `.form-field`. The outer wrapper must be:

```html
<div class="my-20 bg-sodap-600 py-20 appoinment wow fadeIn" data-wow-delay="0.1s">
    <div class="site-container pt-10">
        <div class="grid gap-12 lg:grid-cols-2">
            <div class="wow fadeIn" data-wow-delay="0.3s"></div>
            <div class="wow fadeIn" data-wow-delay="0.8s"></div>
        </div>
    </div>
</div>
```

Inside the left child, preserve the existing SODAP-CI heading, paragraph, address, email, and phone text. Convert every contact row to `class="flex items-start"` and every icon box to `class="btn-lg-square bg-white"`.

Inside the right child, keep field ids exactly as `name`, `mail`, `mobile`, `service`, and `message`. Replace each input, select, and textarea class with `form-field`. Replace the submit button class with `btn btn-primary w-full py-4`.

- [ ] **Step 6: Replace footer and copyright layout**

Use:

```html
<div class="bg-gray-950 py-20 footer wow fadeIn" data-wow-delay="0.1s">
    <div class="site-container">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
            <div></div>
            <div></div>
            <div></div>
            <div></div>
        </div>
    </div>
</div>

<div class="py-6 text-gray-600 copyright">
    <div class="site-container">
        <div class="grid gap-4 md:grid-cols-2">
            <div></div>
            <div class="md:text-right"></div>
        </div>
    </div>
</div>
```

Footer column 1 contains `Our Office`, the three office contact lines, and four social buttons. Footer column 2 contains `Quick Links` and the five current links. Footer column 3 contains `Business Hours` and the current weekday/Saturday/Sunday schedule. Footer column 4 contains `Gallery` and the six current service images in a `grid grid-cols-3 gap-1`.

- [ ] **Step 7: Replace back-to-top markup**

Use:

```html
<a href="#" class="back-to-top btn btn-primary btn-lg-square fixed bottom-8 right-8 z-[99] hidden">
    <i class="bi bi-arrow-up"></i>
</a>
```

- [ ] **Step 8: Replace scripts at the end of body**

Change:

```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
...
<script src="js/main.js"></script>
```

To:

```html
<script src="lib/wow/wow.min.js"></script>
<script src="js/tailwind-main.js"></script>
```

- [ ] **Step 9: Build Tailwind CSS**

Run:

```bash
npm run build:css
```

Expected: exit code 0 and `css/tailwind.css` contains generated classes for the migrated page.

- [ ] **Step 10: Run the pilot test and verify it passes**

Run:

```bash
npm run check:index
```

Expected: PASS with `Index Tailwind pilot guardrails passed`.

- [ ] **Step 11: Commit the remaining index migration**

```bash
git add index.html css/tailwind.css
git commit -m "feat: migrate index content to tailwind"
```

## Task 6: Verify the Pilot in Browser and Final Checks

**Files:**
- Modify only if verification reveals a specific issue: `index.html`, `src/input.css`, `js/tailwind-main.js`

- [ ] **Step 1: Run all structural checks**

Run:

```bash
npm run check
```

Expected:

```text
Tailwind setup guardrails passed
Index Tailwind pilot guardrails passed
```

- [ ] **Step 2: Build CSS once more**

Run:

```bash
npm run build:css
```

Expected: exit code 0.

- [ ] **Step 3: Serve the static site locally**

Run:

```bash
python -m http.server 4173
```

Expected: server available at `http://localhost:4173/`.

- [ ] **Step 4: Inspect desktop manually**

Open:

```text
http://localhost:4173/index.html
```

Verify:

- Topbar appears on desktop.
- Navbar links align horizontally.
- Projects dropdown opens and closes.
- Hero image and text are visible.
- Hero indicators switch slides.
- Activity and project cards align in grids.
- Contact form fields are readable.
- Footer columns align.
- Back-to-top button appears after scrolling.

- [ ] **Step 5: Inspect mobile manually**

Use a mobile viewport around 390px wide and verify:

- Topbar is hidden.
- Menu button opens and closes navigation.
- Projects dropdown opens from the mobile menu.
- Hero text stays inside the viewport.
- Cards stack vertically with clean spacing.
- No form field or footer text overflows.

- [ ] **Step 6: Fix any visual regressions with focused edits**

If a visual issue appears, edit only the smallest relevant area:

- `src/input.css` for shared component styles.
- `index.html` for incorrect Tailwind classes or markup.
- `js/tailwind-main.js` for interaction behavior.

After each fix, run:

```bash
npm run build:css
npm run check
```

Expected: both commands exit 0.

- [ ] **Step 7: Final git status review**

Run:

```bash
git status --short
```

Expected: only intentional migration files are modified or committed. Existing unrelated user edits on other HTML pages must not be reverted.

- [ ] **Step 8: Commit final verification fixes if any**

If Task 6 created fixes, commit them:

```bash
git add index.html src/input.css css/tailwind.css js/tailwind-main.js
git commit -m "fix: polish tailwind pilot responsiveness"
```

## Self-Review Notes

- Spec coverage: local Tailwind setup is in Task 1, pilot guardrails in Task 2, Bootstrap-free JS in Task 3, `index.html` migration in Tasks 4 and 5, verification in Task 6.
- Bootstrap removal for the pilot is checked by `tools/check-index-tailwind-pilot.mjs`.
- The plan keeps all non-pilot pages on Bootstrap during phase one.
- The plan does not delete Bootstrap assets or refactor all of `css/style.css`.
- The plan uses Tailwind v4 CSS-first directives from the official docs while keeping `tailwind.config.js` as a documented project config file for editor/tooling compatibility.
