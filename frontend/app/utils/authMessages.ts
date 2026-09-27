// Friendly text for backend error codes the auth screens can receive
// (API errors and ?social_error= codes from the social-login callback).

const messages: Record<string, string> = {
  INVALID_CREDENTIALS: 'The email or password is incorrect.',
  ACCOUNT_SUSPENDED: 'This account is suspended. Contact NaSeRy support.',
  ACCOUNT_DEACTIVATED: 'This account has been deactivated.',
  RATE_LIMITED: 'Too many attempts. Please wait a moment and try again.',
  INVALID_CODE: 'That code is incorrect or no longer valid.',
  CODE_EXPIRED: 'That code has expired. Request a new one.',
  TOO_MANY_ATTEMPTS: 'Too many incorrect attempts. Request a new code.',
  RESEND_COOLDOWN: 'Please wait before requesting another code.',
  EMAIL_ALREADY_VERIFIED: 'Your email is already verified.',
  SOCIAL_PROVIDER_NOT_CONFIGURED: 'This sign-in option is not available yet.',
  SOCIAL_LOGIN_FAILED: 'We could not complete that sign-in. Please try again.',
  SOCIAL_LOGIN_CANCELLED: 'Sign-in was cancelled.',
  SOCIAL_LOGIN_EXPIRED: 'That sign-in expired. Please start again from this browser.',
  SOCIAL_ACCOUNT_NOT_REGISTERED: 'No NaSeRy account uses that sign-in yet. Create an account first.',
  SOCIAL_EMAIL_UNVERIFIED: 'Verify your email with that provider first, then try again.',
  NETWORK_ERROR: 'Cannot reach the server. Check your connection.',
}

export function authMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return messages[error.code] ?? error.message
  }
  if (typeof error === 'string') {
    return messages[error] ?? 'Something went wrong. Please try again.'
  }
  return 'Something went wrong. Please try again.'
}

/** First validation message per field, for inline form errors. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.code !== 'VALIDATION_ERROR') return {}
  return Object.fromEntries(Object.entries(error.fields).map(([field, list]) => [field, list[0] ?? '']))
}
