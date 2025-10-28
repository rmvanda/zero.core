<?php

namespace Zero\Core;

/**
 * User Class
 *
 * Provides convenient methods for accessing user session data
 * and checking authentication status.
 *
 * @author James Pope
 */
class User
{
    /**
     * Check if user is logged in and verified
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['email']) &&
               isset($_SESSION['verified']) &&
               $_SESSION['verified'];
    }

    /**
     * Get user's full name
     *
     * @return string|null
     */
    public static function getName(): ?string
    {
        return $_SESSION['name'] ?? $_SESSION['user']['full_name'] ?? null;
    }

    /**
     * Get user's profile picture URL
     *
     * @return string|null
     */
    public static function getPicture(): ?string
    {
        return $_SESSION['pic'] ?? $_SESSION['user']['pic'] ?? null;
    }

    /**
     * Get user's email
     *
     * @return string|null
     */
    public static function getEmail(): ?string
    {
        return $_SESSION['email'] ?? $_SESSION['user']['email'] ?? null;
    }

    /**
     * Get user's ID
     *
     * @return mixed
     */
    public static function getId()
    {
        return $_SESSION['user']['id'] ?? null;
    }

    /**
     * Get user's auth level
     *
     * @return int
     */
    public static function getAuthLevel(): int
    {
        return $_SESSION['auth_level'] ?? 0;
    }

    /**
     * Check if user is verified
     *
     * @return bool
     */
    public static function isVerified(): bool
    {
        return isset($_SESSION['verified']) && $_SESSION['verified'];
    }

    /**
     * Get all user session data
     *
     * @return array
     */
    public static function getAll(): array
    {
        return $_SESSION['user'] ?? [];
    }

    /**
     * Logout user by clearing session
     *
     * @return void
     */
    public static function logout(): void
    {
        session_unset();
        session_destroy();
    }
}
