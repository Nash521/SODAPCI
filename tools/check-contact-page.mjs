import { readFileSync } from "node:fs";

const html = readFileSync("contact.html", "utf8");
const contactPageMatch = html.match(/<!-- Page Header Start -->[\s\S]*?<!-- Contact CTA End -->/);
const contactPageHtml = contactPageMatch ? contactPageMatch[0] : "";

const mustInclude = [
  "Contactez SODAP-CI",
  "Une question, un projet, un partenariat",
  "contact-hero",
  "contact-card",
  "contact-shell",
  "contact-form-panel",
  "contact-map-panel",
  "Yamoussoukro, quartier 50 logements",
  "lasodapci@gmail.com",
  "+225 27-33-75-73-12",
  "Demander un accompagnement",
  "Nos coordonnées",
  "Réponse sous 24h ouvrées",
  "contact-card:hover",
  ".contact-form-panel .contact-field:focus",
  ".contact-submit::after",
];

const mustNotInclude = [
  "Have Any Query",
  "Contact Us",
  "Your Name",
  "Your Email",
  "Submit Now",
  "info@example.com",
  "123 Street, New York, USA",
  "New%20York",
  "rounded-4",
  "rounded-pill",
  "text-white-50",
  "fw-bold",
  "display-4",
  " p-lg-5",
  " mb-lg-5",
  "text-lg-end",
  "align-items-stretch",
];

const failures = [];

const forms = [...html.matchAll(/<form\b[^>]*>[\s\S]*?<\/form>/gi)].map((match) => match[0]);
const contactForm = forms.find((form) => /\bid="formulaire-contact"/.test(form)) ?? "";
const applicationForm = forms.find((form) => form.includes("Demande d'emploi")) ?? "";

if (!contactPageHtml) {
  failures.push("Unable to locate contact page content between expected comments.");
}

for (const snippet of mustInclude) {
  if (!html.includes(snippet)) {
    failures.push(`Missing expected snippet: ${snippet}`);
  }
}

for (const snippet of mustNotInclude) {
  if (html.includes(snippet)) {
    failures.push(`Unexpected template snippet remains: ${snippet}`);
  }
}

if (!/data-form-endpoint="\/api\/contact"/.test(contactForm)) {
  failures.push("Contact form must include data-form-endpoint=\"/api/contact\".");
}

if (!/data-form-endpoint="\/api\/candidature"/.test(applicationForm)) {
  failures.push("Application form must include data-form-endpoint=\"/api/candidature\".");
}

if (!/<input\b[^>]*\bname="website"[^>]*>/.test(contactForm)) {
  failures.push("Contact form must include a honeypot input named website.");
}

if (!/<input\b[^>]*\bname="website"[^>]*>/.test(applicationForm)) {
  failures.push("Application form must include a honeypot input named website.");
}

if (!/<script\b[^>]*\bsrc="js\/contact-forms\.js"[^>]*><\/script>/.test(html)) {
  failures.push("Contact page must load js/contact-forms.js.");
}

const classTokens = [
  ...contactPageHtml.matchAll(/class="([^"]+)"/g),
].flatMap((match) => match[1].split(/\s+/).filter(Boolean));

const legacyContactTokens = new Set([
  "container",
  "container-fluid",
  "row",
  "form-floating",
  "form-control",
  "form-select",
  "breadcrumb",
  "breadcrumb-item",
  "d-flex",
  "me-3",
  "mt-lg-0",
  "rounded-circle",
  "position-relative",
  "position-absolute",
  "w-100",
  "h-100",
]);

for (const token of classTokens) {
  if (legacyContactTokens.has(token) || /^col-(?:\d+|sm|md|lg|xl)/.test(token)) {
    failures.push(`Legacy Bootstrap class remains in contact page content: ${token}`);
  }
}

if (failures.length > 0) {
  console.error("Contact page verification failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log("Contact page verification passed.");
