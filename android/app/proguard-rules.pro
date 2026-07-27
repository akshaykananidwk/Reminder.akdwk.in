# Krishna Reminder — release rules

# Retrofit / OkHttp / Gson models are reflected over.
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations
-keepclassmembers,allowshrinking,allowobfuscation interface * { @retrofit2.http.* <methods>; }
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn retrofit2.**

-keep class com.akdwk.krishnareminder.data.api.dto.** { *; }
-keep class com.akdwk.krishnareminder.data.local.** { *; }

# Kotlin serialisation of Gson-annotated fields
-keepclassmembers class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

# Room
-keep class * extends androidx.room.RoomDatabase
-dontwarn androidx.room.paging.**

# Firebase messaging service is referenced from the manifest only.
-keep class com.akdwk.krishnareminder.push.** { *; }
-keep class com.akdwk.krishnareminder.call.** { *; }
-keep class com.akdwk.krishnareminder.alarm.** { *; }
