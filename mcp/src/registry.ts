// Single source of truth for every tool's valid argument values.
//
// These lists were previously duplicated between src/index.ts and
// tests/tools.test.ts. That is exactly how the `migration-v2-v3` topic shipped
// untested: the suite iterated its own stale copy of the list, so a topic added
// to the server was never exercised and the suite still went green.
//
// Import from here. Never re-declare a list in a test — tests/tools.test.ts
// asserts these stay in lockstep with the content maps in both directions.

export const VALID_ENUMS = [
  "InvoiceTypeCode",
  "MessageFilter",
  "ExecutionStatus",
  "DocumentStandardType",
  "StandardType",
  "TaxCategoryId",
  "UploadStatusValue",
  "RegistrationStatus",
] as const;

export const VALID_DTOS = [
  "InvoiceData", "InvoiceLineData", "PartyData", "InvoiceAddressData",
  "UploadOptionsData", "OAuthTokensData", "AuthUrlSettingsData",
  "ListMessagesParamsData", "PaginatedMessagesParamsData",
  "UploadResponseData", "StatusResponseData", "DownloadResponseData",
  "ValidationResultData", "ListMessagesResponseData",
  "PaginatedMessagesResponseData", "MessageDetailsData",
  "CompanyData", "CompanyLookupResultData", "CompanyAddressData",
  "VatRegistrationData", "SplitVatData", "InactiveStatusData",
  "VatPeriodData",
] as const;

export const VALID_TOPICS = [
  "overview", "invoice-flow", "credit-notes", "tax-calculation",
  "oauth-flow", "error-handling", "address-sanitization",
  "rate-limiting", "company-lookup", "migration-v2-v3",
] as const;

export const VALID_SERVICES = [
  "EFacturaClient", "AnafAuthenticator", "UblBuilder",
  "InvoiceBuilder", "AnafDetailsClient",
] as const;
