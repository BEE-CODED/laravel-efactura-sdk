import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { configReferenceContent } from "./content/config-reference.js";
import { enumValuesContent } from "./content/enum-values.js";
import { dtoStructuresContent } from "./content/dto-structures.js";
import { sdkDocsContent } from "./content/sdk-docs.js";
import { apiReferenceContent } from "./content/api-reference.js";

const server = new McpServer({
  name: "efactura-sdk",
  version: "1.0.0",
});

const VALID_ENUMS = [
  "InvoiceTypeCode",
  "MessageFilter",
  "ExecutionStatus",
  "DocumentStandardType",
  "StandardType",
  "TaxCategoryId",
  "UploadStatusValue",
] as const;

server.tool(
  "get-config-reference",
  "Get the full configuration schema for the Laravel e-Factura SDK",
  {},
  async () => ({
    content: [{ type: "text" as const, text: configReferenceContent }],
  })
);

server.tool(
  "get-enum-values",
  "Get all values for a Laravel e-Factura SDK enum",
  { name: z.enum(VALID_ENUMS).describe("Enum name") },
  async ({ name }) => {
    const content = enumValuesContent[name];
    if (!content) {
      return {
        isError: true,
        content: [
          {
            type: "text" as const,
            text: `Unknown enum "${name}". Valid enums: ${VALID_ENUMS.join(", ")}`,
          },
        ],
      };
    }
    return { content: [{ type: "text" as const, text: content }] };
  }
);

const VALID_DTOS = [
  "InvoiceData", "InvoiceLineData", "PartyData", "InvoiceAddressData",
  "UploadOptionsData", "OAuthTokensData", "AuthUrlSettingsData",
  "ListMessagesParamsData", "PaginatedMessagesParamsData",
  "UploadResponseData", "StatusResponseData", "DownloadResponseData",
  "ValidationResultData", "ListMessagesResponseData",
  "PaginatedMessagesResponseData", "MessageDetailsData",
  "CompanyData", "CompanyLookupResultData", "CompanyAddressData",
  "VatRegistrationData", "SplitVatData", "InactiveStatusData",
] as const;

server.tool(
  "get-dto-structure",
  "Get the complete structure of a Laravel e-Factura SDK data transfer object",
  { name: z.enum(VALID_DTOS).describe("DTO class name") },
  async ({ name }) => {
    const content = dtoStructuresContent[name];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text" as const, text: `Unknown DTO "${name}". Valid DTOs: ${VALID_DTOS.join(", ")}` }],
      };
    }
    return { content: [{ type: "text" as const, text: content }] };
  }
);

const VALID_TOPICS = [
  "overview", "invoice-flow", "credit-notes", "tax-calculation",
  "oauth-flow", "error-handling", "address-sanitization",
  "rate-limiting", "company-lookup",
] as const;

server.tool(
  "get-sdk-docs",
  "Get documentation about the Laravel e-Factura SDK for a specific topic",
  { topic: z.enum(VALID_TOPICS).describe("Documentation topic") },
  async ({ topic }) => {
    const content = sdkDocsContent[topic];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text" as const, text: `Unknown topic "${topic}". Valid topics: ${VALID_TOPICS.join(", ")}` }],
      };
    }
    return { content: [{ type: "text" as const, text: content }] };
  }
);

const VALID_SERVICES = [
  "EFacturaClient", "AnafAuthenticator", "UblBuilder",
  "InvoiceBuilder", "AnafDetailsClient",
] as const;

server.tool(
  "get-api-reference",
  "Get API method documentation for a Laravel e-Factura SDK service",
  { service: z.enum(VALID_SERVICES).describe("Service class name") },
  async ({ service }) => {
    const content = apiReferenceContent[service];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text" as const, text: `Unknown service "${service}". Valid services: ${VALID_SERVICES.join(", ")}` }],
      };
    }
    return { content: [{ type: "text" as const, text: content }] };
  }
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("efactura-sdk MCP server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
