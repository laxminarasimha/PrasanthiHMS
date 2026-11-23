// The app's settings are in app.json. This adds "Sign in with Google" when the hospital's Google ids are given at build
// time (they are not secret): GOOGLE_IOS_CLIENT_ID and GOOGLE_WEB_CLIENT_ID, set under "env" in eas.json. Without them
// the app builds as before, with no Google button.
module.exports = ({ config }) => {
  const ios = process.env.GOOGLE_IOS_CLIENT_ID || ''
  const web = process.env.GOOGLE_WEB_CLIENT_ID || ''
  const on = Boolean(ios && web)
  // Google sends the person back to the app at the iPhone id written backwards.
  const scheme = on ? `com.googleusercontent.apps.${ios.replace(/\.apps\.googleusercontent\.com$/, '')}` : ''

  return {
    ...config,
    plugins: [...(config.plugins ?? []), ...(on ? [['@react-native-google-signin/google-signin', { iosUrlScheme: scheme }]] : [])],
    extra: { ...config.extra, googleIosClientId: on ? ios : null, googleWebClientId: on ? web : null },
  }
}
