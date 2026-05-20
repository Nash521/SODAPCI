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

const about = read("apropos.html");

assert(about.includes('href="css/tailwind.css"'), "apropos.html must load css/tailwind.css");
assert(
  about.includes("Entreprise agropastorale &amp; environnementale") ||
    about.includes("Entreprise agropastorale & environnementale"),
  "apropos.html must include the new hero badge"
);
assert(about.includes("Redonner vie aux terres, construire un avenir durable"), "apropos.html must include the new hero title");
assert(about.includes("Notre mission"), "apropos.html must include the mission section");
assert(about.includes("Notre vision"), "apropos.html must include the vision section");
assert(about.includes("Les valeurs qui guident nos actions"), "apropos.html must include the values heading");
assert(
  about.includes("Responsabilit&eacute; environnementale") ||
    about.includes("Responsabilité environnementale"),
  "apropos.html must include the environmental responsibility value"
);
assert(
  about.includes("Engagement communautaire"),
  "apropos.html must include the community value"
);
assert(about.includes('src="img/carousel-2.JPG"') || about.includes("bg-[url('../img/carousel-2.JPG')]"), "apropos.html must use img/carousel-2.JPG in the hero");
assert(about.includes('src="img/about.JPG"'), "apropos.html must use img/about.JPG in the mission/vision section");
assert(!about.includes("Quality Welding"), "apropos.html must remove legacy feature copy");
assert(!about.includes("Meet Our Professional and Experience Welder"), "apropos.html must remove the legacy team section");
assert(!about.includes(">Newsletter<"), "apropos.html must remove the newsletter section");

console.log("Apropos Tailwind guardrails passed");
