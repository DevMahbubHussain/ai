# Revision Change Summary

## Summary

The Revision Change Summary experiment explains, in plain language, what changed between two revisions of a post. It adds a "Summarize changes" action to the visual revisions screen in the block editor and to the classic revisions screen. The summary is read-only: nothing in the post or its revisions is changed or saved. The experiment registers a WordPress Ability (`ai/revision-change-summary`) that can be used through the admin UI and directly via REST API requests.

## Overview

### For End Users

The revisions screens show an exact comparison of two revisions, which can be slow to read on long posts. With this experiment enabled:

- **Visual revisions (block editor):** open a revision, then click the "Change summary" button in the revisions header. A sidebar opens with a "Summarize changes" button. The selected revision is compared with the revision before it, the same comparison core shows.
- **Classic revisions screen (`revision.php`):** a "Summarize changes" panel appears below the revision slider. It compares the two revisions shown, including in "Compare any two revisions" mode. Sites with meta boxes in the editor always use this screen.

The result is a short summary plus a list of up to 10 changes, for example "Rewrote the introduction and added a pricing section." It covers changes to the title, content and excerpt.

**Key Features:**

- Generates only when the user clicks the button
- Read-only: never edits, saves or applies anything
- Results are kept for the page view, so moving back to a summarized revision shows its summary again
- Screen reader announcements for progress, results and errors

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Revision_Change_Summary\Revision_Change_Summary`): Registers the ability and enqueues the scripts for the block editor and for `revision.php`.
2. **Ability Class** (`WordPress\AI\Abilities\Revision_Change_Summary\Revision_Change_Summary`): Validates the revisions, builds a compact diff and asks the model for a structured summary.

## Architecture & Implementation

### Input Schema

```php
array(
    'type'                 => 'object',
    'properties'           => array(
        'post_id'          => array( 'type' => 'integer' ), // Post the revisions belong to.
        'revision_id'      => array( 'type' => 'integer' ), // Newer revision.
        'from_revision_id' => array( 'type' => 'integer' ), // Optional older revision.
    ),
    'required'             => array( 'post_id', 'revision_id' ),
    'additionalProperties' => false,
)
```

If `from_revision_id` is left out, the revision saved just before `revision_id` is used (newest first by date, then ID, autosaves included). If the two revisions are passed in the wrong order, they are swapped so the summary always reads from older to newer.

### Output Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'summary'          => array( 'type' => 'string' ),  // 1-2 sentence summary.
        'changes'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), // Up to 10 changes.
        'truncated'        => array( 'type' => 'boolean' ), // True when only part of the changes was summarized.
        'from_revision_id' => array( 'type' => 'integer' ),
        'revision_id'      => array( 'type' => 'integer' ),
    ),
)
```

### How the Diff Is Built

1. Title and excerpt are compared as plain text.
2. Content is split into one line per block (nested blocks included), using the saved block markup. Blocks, shortcodes and dynamic content are **not rendered**, so nothing runs and the result reflects the revision, not the current site.
3. Lines are normalized with `normalize_content()` and compared with core's `Text_Diff`. Removed lines start with `- `, added lines with `+ `, and one unchanged line of context is kept around each change.
4. If nothing changed in the title, content or excerpt (for example, only meta changed), a fixed message is returned and **no model request is made**.
5. Diffs longer than the limit are cut on a line boundary and `truncated` is set to `true`.

### Permissions

- The user must be able to edit the post (`edit_post`), the same check core uses for revisions.
- Both revisions must belong to that post. Otherwise the request fails with `invalid_revision`.
- The post type must be shown in REST and support revisions.

### Errors

| Code | When |
| --- | --- |
| `post_not_found` | The post doesn't exist. |
| `insufficient_capabilities` | The user can't edit the post. |
| `invalid_revision` | An ID is not a revision of the post. |
| `same_revision` | Both IDs are the same revision. |
| `no_previous_revision` | No `from_revision_id` was given and the revision is the oldest. |
| `invalid_response` / `no_results` | The model returned an unusable response. |
| `unsupported_model` | No connected provider supports text generation. |

## Using the Ability via REST API

### Endpoint

```
POST /wp-json/wp-abilities/v1/abilities/ai/revision-change-summary/run
```

### Authentication

Use cookie authentication with a REST nonce, or an application password. See [TESTING_REST_API.md](../TESTING_REST_API.md).

### Request Example

```bash
curl -X POST "https://example.com/wp-json/wp-abilities/v1/abilities/ai/revision-change-summary/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{"input":{"post_id":123,"revision_id":456}}'
```

Response:

```json
{
  "summary": "Rewrote the introduction and added a closing paragraph.",
  "changes": [
    "Changed the first paragraph to describe the new release.",
    "Added a closing paragraph with a call to action."
  ],
  "truncated": false,
  "from_revision_id": 455,
  "revision_id": 456
}
```

## Customization

### Maximum Diff Length

```php
// Send up to 40,000 characters of changes to the model. Minimum 1,000. Default 20,000.
add_filter( 'wpai_revision_change_summary_max_diff_length', function () {
    return 40000;
} );
```

### Prompts

The system instruction, prompt and prompt builder can be changed with the standard filters, see [PROMPT_CUSTOMIZATION.md](../PROMPT_CUSTOMIZATION.md):

- `wpai_revision_change_summary_system_instruction`
- `wpai_revision_change_summary_prompt` (arguments: prompt, diff, truncated, revision)
- `wpai_revision_change_summary_prompt_builder` (arguments: builder, prompt, truncated)

## Testing

### Manual Testing

1. Enable the experiment in **Settings > AI** and connect a provider that supports text generation.
2. Create a post and save it at least three times with different content.
3. Open the post, then **Revisions** in the document sidebar. Click **Change summary** in the header, then **Summarize changes**.
4. Move the revision slider. The previous summary is hidden; click **Summarize changes** again for the new revision.
5. Open **Open classic revisions screen** and repeat. Try **Compare any two revisions**.
6. Check that the post content is unchanged.

## Notes & Considerations

### Requirements

- WordPress 7.0+ and a connected AI provider with text generation support.
- Post types must support revisions and be shown in REST.

### Limitations

- Core's visual revisions screen has no public extension point. The header button is added to the revisions header markup, and the selected revision is read from the `revision` query arg of the editor URL. If core changes that markup, the button is not shown, but nothing breaks. The classic screen keeps working.
- Meta and taxonomy changes are not summarized yet.
- The site editor (templates, template parts, patterns) is not supported yet.
- When an autosave is a post's only revision, core compares it with the post itself on the classic screen. That comparison can't be summarized.
