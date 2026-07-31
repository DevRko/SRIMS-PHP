<?php
/**
 * SRIMS - Shared <head> + opening <body>
 * Tailwind config below is a 1:1 port of tailwind.config.ts so every
 * utility class used across the original React screens resolves to the
 * exact same colour/spacing/typography values.
 *
 * Expects (optionally) $pageTitle to be set by the including page.
 */
$pageTitle = $pageTitle ?? 'SRIMS';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Srims-<?= e($pageTitle) ?></title>
<link rel="icon" href="<?= BASE_URL ?>/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: {
        sans: ["Inter", "system-ui", "-apple-system", "BlinkMacSystemFont", "Segoe UI", "sans-serif"],
      },
      colors: {
        sidebar: {
          bg: "#1B2A4E", active: "#2563EB", text: "#CBD5E1", "text-hi": "#FFFFFF",
          section: "#64748B", border: "#2D3B5E",
        },
        surface: { app: "#F9FAFB", card: "#FFFFFF" },
        border: { DEFAULT: "#E5E7EB", strong: "#D1D5DB" },
        text: { primary: "#111827", secondary: "#6B7280", muted: "#9CA3AF" },
        brand: { primary: "#2563EB", "primary-hover": "#1D4ED8" },
        status: {
          "pending-bg": "#FEF3C7", "pending-text": "#92400E",
          "approved-bg": "#D1FAE5", "approved-text": "#065F46",
          "issued-bg": "#DBEAFE", "issued-text": "#1E40AF",
          "rejected-bg": "#FEE2E2", "rejected-text": "#991B1B",
          "new-bg": "#E0F2FE", "new-text": "#0369A1",
          "low-bg": "#FFEDD5", "low-text": "#9A3412",
          "critical-bg": "#FEE2E2", "critical-text": "#991B1B",
          "in-stock-bg": "#D1FAE5", "in-stock-text": "#065F46",
          "out-of-stock-bg": "#F3F4F6", "out-of-stock-text": "#374151",
          "partial-bg": "#FEF3C7", "partial-text": "#92400E",
          "draft-bg": "#F3F4F6", "draft-text": "#374151",
        },
        tint: {
          "blue-bg": "#DBEAFE", "blue-icon": "#2563EB",
          "amber-bg": "#FEF3C7", "amber-icon": "#D97706",
          "green-bg": "#D1FAE5", "green-icon": "#059669",
          "purple-bg": "#EDE9FE", "purple-icon": "#7C3AED",
          "red-bg": "#FEE2E2", "red-icon": "#DC2626",
        },
      },
      fontSize: {
        "page-title": ["24px", { lineHeight: "1.3", fontWeight: "700" }],
        "page-subtitle": ["14px", { lineHeight: "1.5", fontWeight: "400" }],
        "card-label": ["13px", { lineHeight: "1.4", fontWeight: "500" }],
        "card-value": ["28px", { lineHeight: "1.2", fontWeight: "700" }],
        "card-delta": ["12px", { lineHeight: "1.4", fontWeight: "500" }],
        "table-header": ["12px", { lineHeight: "1.4", fontWeight: "600" }],
        "table-cell": ["14px", { lineHeight: "1.5", fontWeight: "400" }],
        "status-pill": ["11px", { lineHeight: "1.3", fontWeight: "600" }],
        "sidebar-item": ["14px", { lineHeight: "1.4", fontWeight: "500" }],
        "sidebar-section": ["11px", { lineHeight: "1.3", fontWeight: "600" }],
      },
      borderRadius: { card: "8px", button: "6px", pill: "6px" },
      spacing: {
        "card-padding": "20px", "page-padding": "24px",
        sidebar: "240px", "sidebar-collapsed": "64px", topbar: "64px",
      },
      width: { sidebar: "240px", "sidebar-collapsed": "64px" },
      height: { topbar: "64px" },
      letterSpacing: { "table-header": "0.05em", "sidebar-section": "0.08em" },
    },
  },
};
</script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">
</head>
<body class="font-sans antialiased">
