import { cp, mkdir, readdir, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(fileURLToPath(import.meta.url));
const publish = path.join(root, "dist");
const staticExtensions = new Set([".html", ".js", ".css"]);

await rm(publish, { recursive: true, force: true });
await mkdir(publish, { recursive: true });

for (const entry of await readdir(root, { withFileTypes: true })) {
  if (!entry.isFile()) continue;
  const extension = path.extname(entry.name).toLowerCase();
  if (staticExtensions.has(extension)) {
    await cp(path.join(root, entry.name), path.join(publish, entry.name));
  }
}

await cp(path.join(root, "IMAGE"), path.join(publish, "IMAGE"), { recursive: true });
