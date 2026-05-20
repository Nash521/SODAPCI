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

const btnBlock = inputCss.match(/\.btn\s*\{([\s\S]*?)\n  \}/)?.[1] ?? "";
const btnLightBlock = inputCss.match(/\.btn-light\s*\{([\s\S]*?)\n  \}/)?.[1] ?? "";
const socialBtnBlock = inputCss.match(/\.social-btn\s*\{([\s\S]*?)\n  \}/)?.[1] ?? "";
const socialBtnHoverBlock = inputCss.match(/\.social-btn:hover\s*\{([\s\S]*?)\n  \}/)?.[1] ?? "";
assert(btnBlock.includes("color: #fff;"), "all buttons must default to white text");
assert(btnBlock.includes("box-shadow 0.3s ease"), "buttons must animate elevation smoothly");
assert(inputCss.includes(".btn i,"), "button icons must inherit button text color");
assert(inputCss.includes("color: inherit;"), "button icon color must inherit from the button");
assert(btnLightBlock.includes("color: #fff;"), "light buttons must keep white text");
assert(btnLightBlock.includes("background: var(--color-sodap-700);"), "light buttons must use a dark enough background for white icons");
assert(socialBtnBlock.includes("background: var(--color-sodap-700);"), "social buttons must keep a stable dark background");
assert(socialBtnBlock.includes("color: #fff;"), "social button icons must stay white");
assert(socialBtnHoverBlock.includes("transform: translateY(-1px);"), "social buttons must rise on hover");
assert(socialBtnHoverBlock.includes("box-shadow:"), "social buttons must gain elevation on hover");
assert(!socialBtnHoverBlock.includes("background:"), "social button hover must not change background color");
assert(!socialBtnHoverBlock.includes("color:"), "social button hover must not change icon color");
assert(!socialBtnHoverBlock.includes("border-color:"), "social button hover must not change border color");

const config = read("tailwind.config.js");
assert(config.includes("./*.html"), "tailwind.config.js must document root HTML scanning");
assert(config.includes("./js/**/*.js"), "tailwind.config.js must document JS scanning");
assert(config.includes("sodap"), "tailwind.config.js must document the SODAP-CI theme token");

console.log("Tailwind setup guardrails passed");
