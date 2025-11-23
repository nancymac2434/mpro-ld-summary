# LearnDash Course Toolkit

A comprehensive WordPress plugin for LearnDash that provides powerful shortcodes to display quiz answers, essay responses, and capture Otter form data across all your courses.

## Features

- **Course Summary** - Display all quiz answers and form responses for a course with a single shortcode
- **Quiz Answers** - Show individual or all quiz question answers
- **Essay Answers** - Display essay-type question responses
- **Otter Forms Integration** - Capture and display form submissions from Otter Blocks
- **Quiz Statistics** - Admin tools to manage quiz statistics settings
- **Settings Page** - Easy-to-use admin interface for configuration

## Installation

1. Upload the `learndash-course-toolkit` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > LD Course Toolkit to configure

## Shortcodes

### Course Summary (Recommended)

Display all quiz answers and form responses for a course in one unified, beautifully formatted summary:

```
[ld_course_summary course_id="123"]
```

**Parameters:**
- `course_id` (required) - The LearnDash course ID
- `show_forms` (optional) - Show form data (default: "yes")
- `show_quizzes` (optional) - Show quiz answers (default: "yes")
- `debug` (optional) - Enable debug mode with `debug="1"` to see technical details (default: "0")

**Example:**
```
[ld_course_summary course_id="456" show_forms="yes" show_quizzes="yes"]
```

This shortcode automatically:
- Finds all quizzes in the specified course
- Retrieves all questions from those quizzes
- Displays the user's answers in the order they completed them
- Includes all Otter form responses
- Shows everything in a clean, organized format

### Individual Quiz Answer

Display a specific quiz question answer:

```
[ld_qanswer quiz_id="123" question_post_id="456"]
```

**Parameters:**
- `quiz_id` (required) - The quiz post ID
- `question_post_id` (required) - The question post ID
- `show` (optional) - Display mode: "correct" or "selected" (default: "correct")
- `label` (optional) - Label text (default: "Your answer")
- `debug` (optional) - Enable debug mode (default: "0")

**Examples:**
```
[ld_qanswer quiz_id="123" question_post_id="456" show="correct" label="Your answer"]
[ld_qanswer quiz_id="123" question_post_id="789" show="selected"]
```

### Essay Answers

Display essay-type question responses (supports multiple questions):

```
[ld_essay_answer quiz_id="123" question_post_id="456"]
```

**Parameters:**
- `quiz_id` (required) - The quiz post ID
- `question_post_id` (required) - Question post ID(s), comma-separated for multiple
- `label` (optional) - Label text (default: "Your answer")
- `debug` (optional) - Enable debug mode (default: "0")

**Examples:**
```
[ld_essay_answer quiz_id="123" question_post_id="456"]
[ld_essay_answer quiz_id="123" question_post_id="456,789,012" label="Your essays"]
```

### Otter Form Data

#### Show Single Field Value
```
[aotter_show field="Field Name" fallback="Not answered"]
```

**Parameters:**
- `field` (required) - The form field name/label
- `fallback` (optional) - Text to show if no answer exists

#### Show All Latest Form Responses
```
[aotter_all_latest]
```

Displays all the user's latest form responses in a formatted list.

#### Show Field from Specific Page
```
[aotter_page field="Field Name" page_id="123" fallback=""]
```

**Parameters:**
- `field` (required) - The form field name/label
- `page_id` (optional) - The page ID (default: current page)
- `fallback` (optional) - Text to show if no answer exists

#### Debug Form Data
```
[aotter_dump]
```

Shows all captured form data in JSON format for debugging.

## Admin Tools

### Quiz Statistics Toggle

Administrators can enable/disable quiz statistics for all quizzes via URL parameters:

- **Preview status**: `wp-admin/?ld_stats_all=preview`
- **Enable statistics**: `wp-admin/?ld_stats_all=on`
- **Disable statistics**: `wp-admin/?ld_stats_all=off`
- **Keep IP lock**: Add `&keep_ip=1` to preserve IP lock settings

**Example:**
```
https://yoursite.com/wp-admin/?ld_stats_all=on
```

## Settings

Access plugin settings at **Settings > LD Course Toolkit** to:
- Enable/disable specific features
- View all available shortcodes
- Access documentation

## Use Cases

### 1. Course Completion Summary Page

Create a page that shows students everything they've learned:

```
<h1>Your Course Completion Summary</h1>
<p>Congratulations on completing the course! Here's a summary of all your responses:</p>

[ld_course_summary course_id="123"]
```

### 2. Individual Lesson Review

Show specific quiz answers on lesson pages:

```
<h2>Review Your Answer</h2>
[ld_qanswer quiz_id="456" question_post_id="789" label="What you answered"]
```

### 3. Portfolio or Certificate Page

Combine form data and quiz answers to create a personalized portfolio:

```
<h1>Your Learning Portfolio</h1>

<h2>About You</h2>
<p><strong>Name:</strong> [aotter_show field="Full Name"]</p>
<p><strong>Goal:</strong> [aotter_show field="Learning Goal"]</p>

<h2>Course Completion</h2>
[ld_course_summary course_id="123"]
```

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- LearnDash plugin
- Otter Blocks plugin (optional, for form capture features)

## Frequently Asked Questions

### How do I find my course ID?

Go to LearnDash LMS > Courses in your WordPress admin. Edit the course you want, and look at the URL. The number after `post=` is your course ID.

### How do I find quiz and question IDs?

- **Quiz ID**: Edit the quiz in LearnDash, look for the number in the URL after `post=`
- **Question ID**: Edit the question, look for the number in the URL after `post=`

### Can I style the output?

Yes! All output includes CSS classes like `.ldct-course-summary`, `.ldct-quiz`, `.ldct-question`, etc. You can add custom CSS in your theme.

### Does this work with multisite?

Yes, the plugin is multisite-compatible and handles multisite database tables correctly.

### What if a user hasn't completed a quiz yet?

The shortcodes will show a friendly message indicating no attempts have been made yet.

## Changelog

### 1.0.0
- Initial release
- Course summary shortcode with unified display
- Quiz answer display shortcodes
- Essay answer display shortcodes
- Otter form capture and display
- Quiz statistics admin tools
- Settings page

## Support

For bug reports and feature requests, please open an issue on the [GitHub repository](https://github.com/yourusername/learndash-course-toolkit).

## License

GPL v2 or later

## Credits

Developed for LearnDash course creators who want to provide better feedback and summaries to their students.
