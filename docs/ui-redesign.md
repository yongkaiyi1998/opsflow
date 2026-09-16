# OpsFlow UI Redesign

## Goal
Modern enterprise SaaS / finance-operations UI.

## Principles
- Bootstrap 5 + Blade only
- No Tailwind / Vue / React
- Clean, restrained, professional
- Soft neutral background
- White surfaces
- Subtle borders
- Minimal shadows
- Consistent spacing / typography
- Low-saturation status colors
- Avoid default Bootstrap/admin-template look

## App Shell
- Persistent left sidebar
- Compact top bar
- Responsive off-canvas on small screens
- Authorization-aware navigation

## Page Patterns
- List: title + description + primary action + filters + table
- Detail: 70/30 content + summary/action panel
- Approval: sticky action panel + timeline
- Workflow: visualize conditions + approval route

## Phases
UI01 Design System
UI02 App Shell
UI03 Tables / Forms
UI04 Business Detail Pages
UI05 Approval Experience
UI06 Workflow Configuration

## Guardrails
No changes to:
- business logic
- policies
- authorization
- workflow behavior
- money/concurrency
- schema/migrations
- security protections