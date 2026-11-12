"""Refresh the PHP service methods from Google's Discovery documents."""

import json
import re
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parents[1]
DOCS = "https://developers.google.com/my-business/"
LEGACY_SOURCE = DOCS + "samples/mybusiness_google_rest_v4p9.json"
SERVICES = [
    ("AccountManagement", "mybusinessaccountmanagement", "v1", "accountmanagement"),
    ("BusinessInformation", "mybusinessbusinessinformation", "v1", "businessinformation"),
    ("BusinessInformationAlpha", "mybusinessbusinessinformation", "v1alpha", "businessinformation"),
    ("Performance", "businessprofileperformance", "v1", "performance"),
    ("Lodging", "mybusinesslodging", "v1", "lodging"),
    ("Notifications", "mybusinessnotifications", "v1", "notifications"),
    ("PlaceActions", "mybusinessplaceactions", "v1", "placeactions"),
    ("Verifications", "mybusinessverifications", "v1", "verifications"),
    ("MyBusiness", "mybusiness", "v4", None),
]

# v4 keeps the resources that are still used alongside the separate v1 APIs.
LEGACY_RESOURCES = {
    "accounts.locations.localPosts",
    "accounts.locations.media",
    "accounts.locations.media.customers",
    "accounts.locations.reviews",
}
LEGACY_LOCATION_METHODS = {
    "accounts.locations.batchGet",
    "accounts.locations.batchGetReviews",
    "accounts.locations.getFoodMenus",
    "accounts.locations.updateFoodMenus",
    "accounts.locations.getServiceList",
    "accounts.locations.updateServiceList",
}


def fetch(url):
    with urlopen(url, timeout=30) as response:
        return json.load(response)


def all_methods(resources):
    for resource in resources.values():
        yield from resource.get("methods", {}).values()
        yield from all_methods(resource.get("resources", {}))


def method_key(method):
    return method["id"].split(".", 1)[1]


def include_legacy(key):
    return key in LEGACY_LOCATION_METHODS or (
        key.rsplit(".", 1)[0] in LEGACY_RESOURCES
        and key != "accounts.locations.localPosts.reportInsights"
    )


def method_name(key, legacy):
    if legacy:
        if key.startswith("accounts.locations."):
            key = key.removeprefix("accounts.locations.")
        if key == "batchGetReviews":
            key = "reviews.batchGet"
        if key == "batchGet":
            key = "locations.batchGet"
    first, *tail = key.split(".")
    return first + "".join(part[0].upper() + part[1:] for part in tail)


def render_method(method, schemas, host, ref, legacy):
    key = method_key(method)
    name = method_name(key, legacy)
    path = method["path"]
    path_params = list(dict.fromkeys(re.findall(r"\{\+?([\w.]+)\}", path)))
    arguments = [f"string ${param}" for param in path_params]
    mappings = [f"'{param}' => ${param}" for param in path_params]
    query = {
        field: definition for field, definition in method.get("parameters", {}).items()
        if definition["location"] == "query"
    }
    request = method.get("request")
    if key == "accounts.locations.media.startUpload":
        request = None
    if request:
        default = " = []" if not schemas[request["$ref"]].get("properties") else ""
        arguments.append("array|object $body" + default)
    arguments.append("array $query = []")
    resource, operation = key.rsplit(".", 1)
    reference = f"{ref}/{resource}/{operation}"

    lines = ["    /**"]
    if query:
        listed = [field + (" (required)" if data.get("required") else "") for field, data in sorted(query.items())]
        lines.append("     * Query parameters: " + ", ".join(listed) + ".")
    if request:
        lines.append("     * JSON request body: " + request["$ref"] + ".")
    if key in {"accounts.locations.getServiceList", "accounts.locations.updateServiceList"}:
        lines.append("     * @deprecated Use Business Information v1 and its Location.serviceItems field.")
    lines.extend([f"     * @see {reference}", "     */"])
    signature = f"    public function {name}(" + ", ".join(arguments) + "): array"
    if len(signature) > 116:
        signature = f"    public function {name}(\n" + "".join(
            f"        {argument},\n" for argument in arguments
        ) + "    ): array"
    lines.extend([
        signature, "    {", "        return $this->transport->json(",
        f"            '{host}',", f"            '{method['httpMethod']}',", f"            '{path}',",
        "            [" + ", ".join(mappings) + "],", "            $query,",
    ])
    if request:
        lines.append("            $body,")
    lines.extend(["        );", "    }"])
    return "\n".join(lines), (name, method["httpMethod"], path, reference)


def generate_service(specification):
    service, api, version, docs_slug = specification
    host = f"{api}.googleapis.com"
    discovery = LEGACY_SOURCE if version == "v4" else f"https://{host}/$discovery/rest?version={version}"
    doc = fetch(discovery)
    ref = f"{DOCS}reference/rest/v4" if version == "v4" else f"{DOCS}reference/{docs_slug}/rest/{version}"
    methods = []
    for method in sorted(all_methods(doc["resources"]), key=method_key):
        if version != "v4" or include_legacy(method_key(method)):
            methods.append(render_method(method, doc["schemas"], host, ref, version == "v4"))
    signatures = [row[0] for _, row in methods]
    if len(signatures) != len(set(signatures)):
        raise ValueError(f"Method name collision in {service}")
    php = (
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace GoogleBusinessProfile\\Api;\n\n"
        "use GoogleBusinessProfile\\Transport;\n\n"
        f"/** Generated by tools/generate.py from {doc['id']}. */\n"
        f"final class {service}\n{{\n"
        "    public function __construct(private readonly Transport $transport)\n    {\n    }\n\n"
        + "\n\n".join(code for code, _ in methods) + "\n}\n"
    )
    (ROOT / "src" / "Api" / f"{service}.php").write_text(php, encoding="utf-8", newline="\n")
    return service, discovery, doc.get("revision", ""), [row for _, row in methods]


def main():
    with ThreadPoolExecutor() as pool:
        results = list(pool.map(generate_service, SERVICES))

    class_names = [name for name, *_ in SERVICES] + ["MediaUpload"]
    facade = [
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace GoogleBusinessProfile;\n",
        "\n".join(f"use GoogleBusinessProfile\\Api\\{name};" for name in class_names),
        "\n/** @internal Generated by tools/generate.py. */\nabstract class ApiServices\n{",
        "    protected function __construct(private readonly Transport $transport)\n    {\n    }",
    ]
    for name in class_names:
        accessor = name[0].lower() + name[1:]
        facade.append(
            f"\n    public function {accessor}(): {name}\n    {{\n"
            f"        return new {name}($this->transport);\n    }}"
        )
    (ROOT / "src" / "ApiServices.php").write_text("\n".join(facade) + "\n}\n", encoding="utf-8", newline="\n")

    coverage = ["# Методы Google Business Profile", ""]
    for service, discovery, revision, rows in results:
        coverage.extend([
            f"## {service} ({len(rows)})", "",
            f"[Google Discovery]({discovery}) · ревизия `{revision}`", "",
            "| Метод PHP | HTTP |", "|---|---|",
        ])
        coverage.extend(f"| `{method}` | [{verb} `{path}`]({url}) |" for method, verb, path, url in rows)
        coverage.append("")
    coverage.extend([
        "## MediaUpload (1)", "",
        "[media.upload](https://developers.google.com/my-business/reference/media/rest/v1/media/upload)",
        "доступен через методы `upload` для файла и `uploadMetadata` для запроса с JSON.", "",
    ])
    (ROOT / "API.md").write_text("\n".join(coverage), encoding="utf-8", newline="\n")
    print(sum(len(rows) for _, _, _, rows in results) + 1, "versioned REST methods")


if __name__ == "__main__":
    main()
