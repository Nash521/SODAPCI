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
const classAttributes = [...index.matchAll(/class="([^"]*)"/g)].map((match) => match[1]);

assert(cssExists, "css/tailwind.css must be built");
assert(pilotJsExists, "js/tailwind-main.js is required for Bootstrap-free interactions");
assert(index.includes('href="css/tailwind.css"'), "index.html must load css/tailwind.css");
assert(!index.includes('href="css/bootstrap.min.css"'), "index.html must not load Bootstrap CSS");
assert(!index.includes("bootstrap.bundle"), "index.html must not load Bootstrap JS");
assert(!index.includes("data-bs-"), "index.html must not use Bootstrap data attributes");
assert(!index.includes("navbar-toggler"), "index.html must not use Bootstrap navbar toggler classes");
assert(!index.includes("navbar-collapse"), "index.html must not use Bootstrap collapse classes");
assert(!classAttributes.some((className) => /\bcarousel\b/.test(className) || /\bcarousel-/.test(className)), "index.html must not use Bootstrap carousel classes");
assert(index.includes("data-mobile-menu-toggle"), "index.html must expose a mobile menu toggle");
assert(index.includes("data-mobile-menu"), "index.html must expose the mobile menu container");
assert(index.includes("data-projects-toggle"), "index.html must expose the project dropdown toggle");
assert(index.includes("data-projects-menu"), "index.html must expose the project dropdown menu");
assert(index.includes("data-hero-carousel"), "index.html must expose the hero carousel root");
assert(index.includes("data-hero-slide"), "index.html must expose hero slides");
assert(index.includes("data-hero-indicator"), "index.html must expose hero indicators");
assert(index.includes('src="js/tailwind-main.js"'), "index.html must load js/tailwind-main.js");

const pilotJs = read("js/tailwind-main.js");
assert(pilotJs.includes("initMobileMenu"), "tailwind-main.js must initialize the mobile menu");
assert(pilotJs.includes("initProjectsDropdown"), "tailwind-main.js must initialize the project dropdown");
assert(pilotJs.includes("initHeroCarousel"), "tailwind-main.js must initialize the hero carousel");
assert(pilotJs.includes("initBackToTop"), "tailwind-main.js must initialize the back-to-top button");

console.log("Index Tailwind pilot guardrails passed");
