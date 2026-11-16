<?php
// $this->values is raw $_POST, so any field can arrive as an array
// (name[]=x). htmlspecialchars() throws TypeError on one, which at this point
// in the response means a blank page rather than an error.
$val = static fn($v): string => is_string($v) ? $v : '';
?>
<div class="login-container">
    <div class="login-box register-box">
        <?php if (!empty($this->sent)): ?>
            <h2>Check your email</h2>
            <p class="register-sent">If that address can be registered, we've sent it a link.
               Open it to finish signing in. It can only be used once.</p>
        <?php else: ?>
            <h2>Create an account</h2>

            <?php if (!empty($this->errors)): ?>
                <ul class="register-errors">
                    <?php foreach ($this->errors as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form action="/auth/register" method="post" class="register-form">
                <label class="register-field" for="field-email">
                    <span class="register-label">Email Address</span>
                    <input id="field-email" name="email" type="email" required
                           value="<?= htmlspecialchars($val($this->values['email'] ?? '')) ?>">
                </label>

                <label class="register-field" for="field-name">
                    <span class="register-label">Name</span>
                    <input id="field-name" name="name" type="text" required maxlength="191"
                           value="<?= htmlspecialchars($val($this->values['name'] ?? '')) ?>">
                </label>

                <?php foreach ($this->fields as $field):
                    $id         = 'field-' . $field['key'];
                    $fieldValue = $val($this->values[$field['key']] ?? '');
                ?>
                    <label class="register-field" for="<?= htmlspecialchars($id) ?>">
                        <span class="register-label"><?= htmlspecialchars($field['label']) ?></span>

                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($field['key']) ?>"
                                <?= !empty($field['required']) ? 'required' : '' ?>><?= htmlspecialchars($fieldValue) ?></textarea>

                        <?php elseif ($field['type'] === 'select'): ?>
                            <select id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($field['key']) ?>"
                                <?= !empty($field['required']) ? 'required' : '' ?>>
                                <option value="">—</option>
                                <?php foreach ($field['options'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt['value']) ?>"
                                        <?= $fieldValue === $opt['value'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($field['type'] === 'checkbox'): ?>
                            <input id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($field['key']) ?>"
                                   type="checkbox" value="1" <?= $fieldValue ? 'checked' : '' ?>
                                   <?= !empty($field['required']) ? 'required' : '' ?>>

                        <?php else: ?>
                            <input id="<?= htmlspecialchars($id) ?>" name="<?= htmlspecialchars($field['key']) ?>"
                                   type="<?= htmlspecialchars($field['type']) ?>"
                                   value="<?= htmlspecialchars($fieldValue) ?>"
                                   <?= isset($field['pattern']) ? 'pattern="' . htmlspecialchars($field['pattern']) . '"' : '' ?>
                                   <?= !empty($field['required']) ? 'required' : '' ?>>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>

                <button type="submit" class="register-submit">Create account</button>
            </form>

            <div class="register-alt">
                <p>Already have an account? <a href="/user/login">Sign in</a></p>
            </div>
        <?php endif; ?>
    </div>
</div>
