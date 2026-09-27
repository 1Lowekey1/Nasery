<?php

namespace App\Support\Http;

/**
 * Machine-readable error codes returned in error.code. The frontend branches
 * on these, never on message text.
 */
enum ErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case RateLimited = 'RATE_LIMITED';
    case Conflict = 'CONFLICT';
    case BusinessRuleViolation = 'BUSINESS_RULE_VIOLATION';
    case ConcurrencyConflict = 'CONCURRENCY_CONFLICT';
    case CapacityExceeded = 'CAPACITY_EXCEEDED';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
    case BadRequest = 'BAD_REQUEST';

    // Authentication (B1)
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case AccountDeactivated = 'ACCOUNT_DEACTIVATED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case EmailAlreadyVerified = 'EMAIL_ALREADY_VERIFIED';
    case InvalidCode = 'INVALID_CODE';
    case CodeExpired = 'CODE_EXPIRED';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
    case ResendCooldown = 'RESEND_COOLDOWN';
    case SocialProviderNotConfigured = 'SOCIAL_PROVIDER_NOT_CONFIGURED';
    case SocialLoginFailed = 'SOCIAL_LOGIN_FAILED';
    case SocialLoginCancelled = 'SOCIAL_LOGIN_CANCELLED';
    case SocialLoginExpired = 'SOCIAL_LOGIN_EXPIRED';
    case SocialUnlinkBlocked = 'SOCIAL_UNLINK_BLOCKED';

    // Organizations (B2)
    case AccountTypeNotAllowed = 'ACCOUNT_TYPE_NOT_ALLOWED';
    case OrganizationExists = 'ORGANIZATION_EXISTS';
    case OrganizationNotOperational = 'ORGANIZATION_NOT_OPERATIONAL';

    // SaaS plans (B18): the organization's plan doesn't allow this; details say which limit.
    case PlanLimitReached = 'PLAN_LIMIT_REACHED';

    // Inquiries (B4)
    case NoMatchingOrganizers = 'NO_MATCHING_ORGANIZERS';
    case SocialAccountNotRegistered = 'SOCIAL_ACCOUNT_NOT_REGISTERED';
    case SocialEmailUnverified = 'SOCIAL_EMAIL_UNVERIFIED';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case ServerError = 'SERVER_ERROR';
}
