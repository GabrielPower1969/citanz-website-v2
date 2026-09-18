<template>
<div class="page-content donate-page">
  <section-title />
  <page-hero v-if="site_data.hero" :hero="site_data.hero" />

  <section class="section donate-section" v-if="site_data.stripe_payment_link || site_data.bank">
    <v-container>
      <v-row>
        <v-col cols="12" md="8" lg="6" offset-lg="1" v-if="site_data.content">
          <article class="typography donate-page__intro" v-html="site_data.content"></article>
        </v-col>
      </v-row>

      <v-row class="donate-page__methods" :justify="site_data.bank ? 'start' : 'center'">
        <v-col cols="12" lg="5" :offset-lg="site_data.bank ? 1 : 0" v-if="site_data.stripe_payment_link">
          <div class="donate-card donate-card--stripe">
            <header class="donate-card__header">
              <span class="donate-card__eyebrow">Card, Apple Pay or Google Pay</span>
              <h2 class="donate-card__title">Donate online</h2>
            </header>
            <div class="donate-card__body">
              <p class="donate-card__lead">
                Secure checkout by Stripe. You choose the amount, and a receipt is emailed to you.
              </p>

              <div class="donate-card__qr">
                <qrcode-vue :value="site_data.stripe_payment_link" :size="176" level="M" render-as="svg" />
                <span class="donate-card__qr-hint"><v-icon small>mdi-cellphone</v-icon> Scan with your phone camera</span>
              </div>

              <div class="donate-card__divider"><span>or</span></div>

              <v-btn
                class="donate-card__button"
                color="primary"
                depressed
                x-large
                block
                :href="site_data.stripe_payment_link"
                target="_blank"
                rel="noopener"
              >
                Donate now
                <v-icon right>mdi-arrow-right</v-icon>
              </v-btn>
            </div>
          </div>
        </v-col>

        <v-col cols="12" lg="5" v-if="site_data.bank">
          <div class="donate-card donate-card--bank">
            <header class="donate-card__header">
              <span class="donate-card__eyebrow">Internet banking</span>
              <h2 class="donate-card__title">Bank transfer</h2>
            </header>
            <div class="donate-card__body">
              <p class="donate-card__lead">
                Prefer to pay from your bank? Use the details below.
              </p>

              <dl class="donate-card__details">
                <div>
                  <dt>Account name</dt>
                  <dd>{{ site_data.bank.account_name }}</dd>
                </div>
                <div>
                  <dt>Account number</dt>
                  <dd class="donate-card__account">
                    <span ref="accountNumber">{{ site_data.bank.account_number }}</span>
                    <v-btn
                      icon
                      small
                      :title="copied ? 'Copied' : 'Copy account number'"
                      @click="copyAccount"
                    >
                      <v-icon small>{{ copied ? 'mdi-check' : 'mdi-content-copy' }}</v-icon>
                    </v-btn>
                  </dd>
                </div>
              </dl>

              <div v-if="site_data.bank.note" class="donate-card__note typography" v-html="site_data.bank.note"></div>
            </div>
          </div>
        </v-col>
      </v-row>

      <v-row>
        <v-col cols="12" lg="10" offset-lg="1">
          <p class="donate-page__footnote">
            <v-icon small>mdi-heart-outline</v-icon>
            CITANZ is a registered incorporated society run by volunteers. Every contribution goes toward community events and member programmes.
          </p>
        </v-col>
      </v-row>
    </v-container>
  </section>

  <section class="section" v-else>
    <v-container>
      <v-row>
        <v-col cols="12" md="7" offset-md="1">
          <article class="typography" v-html="site_data.content"></article>
        </v-col>
      </v-row>
    </v-container>
  </section>
</div>
</template>

<script>
import QrcodeVue from 'qrcode.vue'

export default {
  name: 'DonatePage',
  components: { QrcodeVue },
  data() {
    return {
      copied: false,
    }
  },
  methods: {
    copyAccount() {
      const number = this.site_data.bank && this.site_data.bank.account_number
      if (!number) {
        return
      }

      const done = () => {
        this.copied = true
        setTimeout(() => { this.copied = false }, 2000)
      }

      // Fallback for browsers that refuse clipboard writes: select the text
      // so the user can copy it themselves.
      const selectText = () => {
        const range = document.createRange()
        range.selectNodeContents(this.$refs.accountNumber)
        const selection = window.getSelection()
        selection.removeAllRanges()
        selection.addRange(range)
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(number).then(done).catch(selectText)
      } else {
        selectText()
      }
    },
  },
}
</script>
