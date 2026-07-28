package com.akdwk.krishnareminder.ui

import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.akdwk.krishnareminder.R
import com.akdwk.krishnareminder.data.prefs.UserPrefs
import com.akdwk.krishnareminder.sync.SyncWorker
import com.akdwk.krishnareminder.ui.screens.AboutScreen
import com.akdwk.krishnareminder.ui.screens.AddReminderScreen
import com.akdwk.krishnareminder.ui.screens.HomeScreen
import com.akdwk.krishnareminder.ui.screens.NotesScreen
import com.akdwk.krishnareminder.ui.screens.LoginScreen
import com.akdwk.krishnareminder.ui.screens.OnboardingScreen
import com.akdwk.krishnareminder.ui.screens.PaymentsScreen
import com.akdwk.krishnareminder.ui.screens.PermissionsScreen
import com.akdwk.krishnareminder.ui.screens.RemindersScreen
import com.akdwk.krishnareminder.ui.screens.SettingsScreen
import com.akdwk.krishnareminder.ui.theme.KrishnaTheme
import com.google.firebase.messaging.FirebaseMessaging

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val prefs = UserPrefs.get(this)

        // Text shared from another app becomes a pre-filled quick add.
        val sharedText = if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            intent.getStringExtra(Intent.EXTRA_TEXT).orEmpty()
        } else {
            ""
        }

        captureFcmToken(prefs)

        setContent {
            val theme = remember { prefs.theme }

            KrishnaTheme(themePreference = theme) {
                AppRoot(sharedText = sharedText)
            }
        }
    }

    override fun onResume() {
        super.onResume()

        if (UserPrefs.get(this).isLoggedIn) {
            SyncWorker.runOnce(this)
        }
    }

    /** The token is needed before the first push; it is re-sent on every login. */
    private fun captureFcmToken(prefs: UserPrefs) {
        try {
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                if (task.isSuccessful) {
                    prefs.fcmToken = task.result
                }
            }
        } catch (e: Exception) {
            // Firebase not configured in this build — WhatsApp and local alarms
            // still deliver every reminder.
        }
    }
}

/* ------------------------------------------------------------------ Routes */

object Routes {
    const val ONBOARDING = "onboarding"
    const val PERMISSIONS = "permissions"
    const val LOGIN = "login"
    const val HOME = "home"
    const val REMINDERS = "reminders"
    const val PAYMENTS = "payments"
    const val NOTES = "notes"
    const val SETTINGS = "settings"
    const val ADD = "add"
    const val ABOUT = "about"
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppRoot(sharedText: String) {
    val viewModel: AppViewModel = viewModel()
    val navController = rememberNavController()
    val snackbar = remember { SnackbarHostState() }

    val loggedIn by viewModel.loggedIn.collectAsState()
    val message by viewModel.message.collectAsState()

    // Ask for notification permission as soon as the user is past onboarding.
    val notificationLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { }

    LaunchedEffect(loggedIn) {
        if (loggedIn && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }

        if (loggedIn) {
            viewModel.refresh()
        }
    }

    LaunchedEffect(message) {
        message?.let {
            snackbar.showSnackbar(it)
            viewModel.consumeMessage()
        }
    }

    val start = when {
        !viewModel.prefs.onboarded -> Routes.ONBOARDING
        !loggedIn -> Routes.LOGIN
        else -> Routes.HOME
    }

    val backStack by navController.currentBackStackEntryAsState()
    val currentRoute = backStack?.destination?.hierarchy?.firstOrNull()?.route

    val showChrome = currentRoute in listOf(Routes.HOME, Routes.REMINDERS, Routes.NOTES, Routes.PAYMENTS, Routes.SETTINGS)

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            if (showChrome) {
                NavigationBar {
                    val items = listOf(
                        Triple(Routes.HOME, Icons.Filled.Home, R.string.nav_home),
                        Triple(Routes.REMINDERS, Icons.Filled.CheckCircle, R.string.nav_reminders),
                        Triple(Routes.NOTES, Icons.Filled.Description, R.string.nav_notes),
                        Triple(Routes.PAYMENTS, Icons.Filled.Payments, R.string.nav_payments),
                        Triple(Routes.SETTINGS, Icons.Filled.Settings, R.string.nav_settings)
                    )

                    items.forEach { (route, icon, label) ->
                        NavigationBarItem(
                            selected = currentRoute == route,
                            onClick = {
                                navController.navigate(route) {
                                    popUpTo(navController.graph.findStartDestination().id) { saveState = true }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(icon, contentDescription = stringResource(label)) },
                            label = { Text(stringResource(label)) }
                        )
                    }
                }
            }
        },
        floatingActionButton = {
            if (showChrome) {
                FloatingActionButton(onClick = { navController.navigate(Routes.ADD) }) {
                    Icon(Icons.Filled.Add, contentDescription = null)
                }
            }
        }
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = start,
            modifier = Modifier.padding(padding)
        ) {
            composable(Routes.ONBOARDING) {
                OnboardingScreen(
                    onFinish = {
                        viewModel.prefs.onboarded = true
                        navController.navigate(Routes.PERMISSIONS) { popUpTo(Routes.ONBOARDING) { inclusive = true } }
                    },
                    onLanguageSelected = { viewModel.prefs.language = it }
                )
            }

            composable(Routes.PERMISSIONS) {
                PermissionsScreen(
                    onContinue = {
                        navController.navigate(if (loggedIn) Routes.HOME else Routes.LOGIN) {
                            popUpTo(Routes.PERMISSIONS) { inclusive = true }
                        }
                    }
                )
            }

            composable(Routes.LOGIN) {
                LoginScreen(
                    onLoggedIn = {
                        viewModel.setLoggedIn(true)
                        navController.navigate(Routes.HOME) { popUpTo(Routes.LOGIN) { inclusive = true } }
                    }
                )
            }

            composable(Routes.HOME) {
                HomeScreen(
                    viewModel = viewModel,
                    initialQuickAdd = sharedText,
                    onOpenAdd = { navController.navigate(Routes.ADD) },
                    onOpenReminders = { navController.navigate(Routes.REMINDERS) },
                    onOpenPermissions = { navController.navigate(Routes.PERMISSIONS) }
                )
            }

            composable(Routes.REMINDERS) { RemindersScreen(viewModel) }

            composable(Routes.NOTES) { NotesScreen(viewModel) }
            composable(Routes.PAYMENTS) { PaymentsScreen(viewModel) }

            composable(Routes.SETTINGS) {
                SettingsScreen(
                    viewModel = viewModel,
                    onAbout = { navController.navigate(Routes.ABOUT) },
                    onPermissions = { navController.navigate(Routes.PERMISSIONS) },
                    onLoggedOut = {
                        navController.navigate(Routes.LOGIN) { popUpTo(0) }
                    }
                )
            }

            composable(Routes.ADD) {
                AddReminderScreen(
                    viewModel = viewModel,
                    prefill = sharedText,
                    onDone = { navController.popBackStack() }
                )
            }

            composable(Routes.ABOUT) { AboutScreen(onBack = { navController.popBackStack() }) }
        }
    }
}
