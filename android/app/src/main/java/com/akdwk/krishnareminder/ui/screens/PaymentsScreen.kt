package com.akdwk.krishnareminder.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.akdwk.krishnareminder.ui.AppViewModel
import com.akdwk.krishnareminder.ui.components.StatCard
import com.akdwk.krishnareminder.util.Formatters

@Composable
fun PaymentsScreen(viewModel: AppViewModel) {
    val payments by viewModel.payments.collectAsState()

    LaunchedEffect(Unit) { viewModel.refresh() }

    val receivable = payments.filter { it.direction == "receivable" && it.status != "paid" }
        .sumOf { it.amount - it.paidAmount }

    val payable = payments.filter { it.direction == "payable" && it.status != "paid" }
        .sumOf { it.amount - it.paidAmount }

    LazyColumn(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        item { Spacer(Modifier.height(8.dp)) }

        item {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatCard(Formatters.money(receivable), "To receive", MaterialTheme.colorScheme.tertiary, Modifier.weight(1f))
                StatCard(Formatters.money(payable), "To pay", MaterialTheme.colorScheme.error, Modifier.weight(1f))
            }
        }

        if (payments.isEmpty()) {
            item { EmptyState("💰", "No payments tracked yet.") }
        } else {
            items(payments, key = { it.id }) { payment ->
                val balance = payment.amount - payment.paidAmount

                Card(modifier = Modifier.fillMaxWidth()) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(payment.partyName, style = MaterialTheme.typography.titleMedium)

                            Text(
                                "${payment.direction} · due ${payment.dueDate} · ${payment.status}",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }

                        Column(horizontalAlignment = Alignment.End) {
                            Text(
                                Formatters.money(balance, payment.currency),
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold
                            )

                            if (balance > 0) {
                                FilledTonalButton(onClick = { viewModel.payPayment(payment.id, balance) { } }) {
                                    Text("Mark paid")
                                }
                            }
                        }
                    }
                }
            }
        }

        item { Spacer(Modifier.height(90.dp)) }
    }
}
