import { readFileSync } from "node:fs";

const html = readFileSync("index.html", "utf8");
const failures = [];
const form = html.match(/<form\b(?=[^>]*\bid="appointment-form")[^>]*>[\s\S]*?<\/form>/i)?.[0] ?? "";

if (!form) {
  failures.push("Missing form: #appointment-form");
} else {
  const requiredSnippets = [
    'action="api/send-form.php"',
    'method="post"',
    'enctype="multipart/form-data"',
    'name="form_type" value="appointment"',
    'name="name"',
    'name="email"',
    'name="phone"',
    'name="subject"',
    'name="message"',
    'name="website"',
    'data-email-form',
    'data-form-status',
  ];

  for (const snippet of requiredSnippets) {
    if (!form.includes(snippet)) {
      failures.push(`Missing required snippet in #appointment-form: ${snippet}`);
    }
  }
}

if (failures.length > 0) {
  console.error("Index form verification failed:");
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log("Index form verification passed.");
