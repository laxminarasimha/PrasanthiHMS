import Constants from 'expo-constants'
import { Platform } from 'react-native'

const extra = (Constants.expoConfig?.extra ?? {}) as { googleIosClientId?: string | null; googleWebClientId?: string | null }

/** "Sign in with Google" is offered only in a phone build made with the hospital's Google ids (see app.config.js). */
export const googleReady = Platform.OS !== 'web' && Boolean(extra.googleIosClientId && extra.googleWebClientId)

/**
 * Opens Google's own sign-in on the phone and gives back the signed token Google issues for the hospital, which the
 * server checks. Null when the person closes it without choosing an account.
 */
export async function googleCredential(): Promise<string | null> {
  // Loaded only when used, so the web preview and builds without Google never touch the native module.
  const { GoogleSignin, isSuccessResponse, isErrorWithCode, statusCodes } = await import('@react-native-google-signin/google-signin')
  GoogleSignin.configure({ iosClientId: extra.googleIosClientId ?? undefined, webClientId: extra.googleWebClientId ?? undefined })
  try {
    // Forget the last choice, so the person picks their account each time (a shared phone may hold several).
    await GoogleSignin.signOut().catch(() => null)
    const res = await GoogleSignin.signIn()
    if (!isSuccessResponse(res)) return null
    if (!res.data.idToken) throw new Error('Google did not send a sign-in token. Please try again.')
    return res.data.idToken
  } catch (e) {
    if (isErrorWithCode(e) && (e.code === statusCodes.SIGN_IN_CANCELLED || e.code === statusCodes.IN_PROGRESS)) return null
    throw e instanceof Error ? e : new Error('Google sign-in did not go through. Please try again.')
  }
}
