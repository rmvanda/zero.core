# A `MailSent` entity for the mail log

**Status:** not built. A possible improvement.

## How mail is logged

`Zero\Lib\Mail\Mail::sendEmailTo()` records each message as a `Zero\Entity\Event` with
`event = 'Mail Sent'`. An `Event` has four attributes: `datetime`, `event`, `detail` and `ip`.
`Mail` puts its six fields (`to`, `subject`, `from`, `reply_to`, `success`, `message_length`,
plus `captured` and `sent` when mail capture is on) into `detail` as one JSON string.

An entity value holds 255 characters. `Event` cuts a longer `detail` to 255 characters and
writes a Console warning. A cut JSON string is not valid JSON, so a row with a long subject
loses its last fields.

## The improvement

Give mail its own entity type, with one attribute for each field.

| Attribute | Holds |
|---|---|
| `datetime` | when the message was sent |
| `to` | the recipient |
| `from` | the sender |
| `reply_to` | the Reply-To address |
| `subject` | the subject, cut to 255 characters |
| `success` | 1 or 0 |
| `captured` | 1 when `MAIL_CAPTURE_DIR` wrote the message to a file |
| `message_length` | the length of the body |
| `ip` | the client address of the request that sent it |

- A class `Zero\Entity\MailSent` in `core/entity/`, like `Event`.
- `Mail::sendEmailTo()` creates a `MailSent` and builds no JSON.
- The body is not stored: a sign-in message holds a live link.

## What it gives

- Each field has its own 255 characters. Only a long subject is cut, and the row stays whole.
- `mailsent_view` has a column for each field, so "all mail to this address" is a plain query.
- The event log holds events only.

## What it costs

- Existing `Mail Sent` events stay in the event log, or a one-time script converts them.
- Any reader of `Mail Sent` events must change. There is none in core.
