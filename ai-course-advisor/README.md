# AI Course Advisor (WordPress Plugin)

AI Course Advisor is a self-hosted WordPress plugin that crawls site content, builds a semantic index, and powers a floating chatbot that recommends the best course/product for each user goal.

## Features

- Auto crawl/index for pages, posts, custom post types, landing pages, and WooCommerce products.
- Vector index table (`wp_ai_course_vectors`) with title, content, embeddings, URLs, and checkout links.
- AI provider support:
  - OpenAI (Embeddings + Chat Completions)
  - Google Gemini (Embedding + Generate Content)
- Floating, mobile-responsive chat widget (vanilla JS + CSS).
- REST endpoint: `/wp-json/ai-course-advisor/v1/chat`
- Auto-sync index on content updates (`save_post`, `publish_post`).
- Basic analytics table for top chatbot questions.

## Installation

1. Copy the `ai-course-advisor` folder to `wp-content/plugins/`.
2. Activate **AI Course Advisor** from WordPress Admin → Plugins.
3. On activation, plugin will:
   - create required database tables
   - initialize default settings
   - crawl & index existing published content
4. Open WordPress Admin → **AI Course Advisor** and configure:
   - API Provider
   - API Key
   - Chatbot Title
   - Primary Color
   - Bot Avatar URL
5. Visit frontend to verify floating chat widget appears.

## Usage Notes

- If API key is not set, plugin uses a local deterministic embedding fallback and returns a limited response.
- If WooCommerce is active and indexed post is a product, booking link is generated as checkout add-to-cart URL.
- If WooCommerce is not active, plugin uses `cta_link` post meta or the page URL.

## File Structure

- `ai-course-advisor.php` - main bootstrap and hooks
- `admin/settings-page.php` - admin UI and settings registration
- `includes/database.php` - custom tables and settings utilities
- `includes/crawler.php` - crawler + index sync logic
- `includes/vector-index.php` - vector table operations + cosine similarity
- `includes/ai-engine.php` - OpenAI/Gemini integrations
- `includes/recommendation-engine.php` - query ranking and prompt builder
- `api/chat-endpoint.php` - REST endpoint handler
- `assets/chat-ui.js` - frontend widget script
- `assets/chat-style.css` - frontend styles

## Security

- Sanitization via `sanitize_text_field`, `sanitize_textarea_field`, `sanitize_hex_color`, and URL escaping.
- REST nonce verification with `wp_verify_nonce`.
- Output escaping using `esc_html`, `esc_attr`, `esc_url`, and `wp_kses_post`.
