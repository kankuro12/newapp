# Signup country and phone

4 October 2026. Country selector and required phone field share one row, with separate labels and stored values. Nepal
(+977) is the initial selection. Users can choose 245 countries/territories.
Country and national phone are saved separately as strings; leading zeroes
remain intact. A pasted international prefix must match the chosen country.
Nepali digits and common spaces, parentheses and hyphens are normalized.

Country choices use one shared JSON file, backend/resources/country-calling-codes.json,
read by registration validation and bundled into Ionic. Calling codes derive
from [Google's official metadata at a pinned revision](https://raw.githubusercontent.com/google/libphonenumber/d347e5d04397c99fe018636eac2f363a27af71c2/resources/PhoneNumberMetadata.xml),
retrieved 4 October 2026. Country display names were generated with Node's
English Intl.DisplayNames. [ITU's numbering-plan directory](https://www.itu.int/oth/T0202.aspx?parent=T0202)
was also verified. This imports factual country/code pairs, not a phone library.

Validation checks a supported country, required national digits and supported
length. It does not certify each country's full numbering plan or verify phone
ownership. No SMS/OTP service is connected. Existing users have nullable fields;
no country/phone was invented for them. Email verification and separate tenant/
platform guards still apply. Business currency/dates remain NPR/BS.

Manual opens at /app/{branch}/help inside the Ionic workspace, from More.
Its local public HTML remains available for printing and approved static PWA
caching. Private API data remains uncached.
